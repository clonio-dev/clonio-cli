#!/usr/bin/env bash
# Registers every client configuration for one server posture, runs connection:test and asserts the
# outcome listed in .github/tls/cases/<family>.cases (PRD-connection-tls §10.1).
# Usage (from the repo root, APP_KEY set): run-matrix.sh <driver> <posture> <certs-dir>
set -uo pipefail

driver="$1"
posture="$2"
certs="$3" # passed to connection:add as given; CI uses the relative "certs" to exercise cwd resolution
here="$(cd "$(dirname "$0")" && pwd)"
# CLONIO_TLS_PORT: same override as in start-server.sh.

if [ -e clonio.json ]; then
  echo "run-matrix.sh needs a directory without clonio.json" >&2
  exit 2
fi

# Host-mismatch cases dial a non-loopback address that is not in the server certificate's SAN.
lan_ip="${CLONIO_TLS_LAN_IP:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
if [ -z "$lan_ip" ]; then
  echo "no non-loopback IP found; set CLONIO_TLS_LAN_IP" >&2
  exit 2
fi

case "$driver" in
  mysql | mariadb)
    family=mysql drivers="mysql mariadb" host=127.0.0.1 port="${CLONIO_TLS_PORT:-3306}"
    db=(--database=clonio_test --username=root --password=secret)
    ;;
  pgsql)
    family=pgsql drivers=pgsql host=127.0.0.1 port="${CLONIO_TLS_PORT:-5432}"
    db=(--database=clonio_test --username=postgres --password=secret --schema=public)
    ;;
  sqlsrv)
    # localhost rather than 127.0.0.1: DNS SANs are matched by every TLS stack, IP SANs not necessarily by ODBC.
    family=sqlsrv drivers=sqlsrv host=localhost port="${CLONIO_TLS_PORT:-1433}"
    db=(--database=master --username=sa --password=Clonio@Strong1)
    ;;
  *)
    echo "unknown driver '$driver'" >&2
    exit 2
    ;;
esac

ca="$certs/ca.pem"
client=(--ssl-cert="$certs/client.pem" --ssl-key="$certs/client-key.pem")
# sqlsrv verifies against the system trust store; certificate paths are a validation error there (§7).
if [ "$family" = sqlsrv ]; then
  verify=(--ssl-mode=verify)
else
  verify=(--ssl-mode=verify --ssl-ca="$ca")
fi

add() { # add <name> <host> [options...]
  local name="$1" dial="$2"
  shift 2
  php clonio connection:add "$name" --type="$driver" --host="$dial" --port="$port" "${db[@]}" "$@" --no-interaction </dev/null
}

# Rewrites one connection in clonio.json, keeping the file's permissions.
edit_json() { # edit_json <jq filter> <name>
  local tmp rc
  tmp="$(mktemp)"
  jq --arg n "$2" "$1" clonio.json > "$tmp" && cat "$tmp" > clonio.json
  rc=$?
  rm -f "$tmp"
  return "$rc"
}

# shellcheck disable=SC2016 # $n in the jq filters is a jq variable, not a shell one
setup_case() { # setup_case <case> <connection name>
  local c="$1" n="$2"
  case "$c" in
    absent) add "$n" "$host" && edit_json 'del(.connections[$n].ssl)' "$n" ;;
    legacy-trust) add "$n" "$host" && edit_json 'del(.connections[$n].ssl) | .connections[$n].trust_server_certificate = true' "$n" ;;
    disable | require) add "$n" "$host" --ssl-mode="$c" ;;
    verify) add "$n" "$host" "${verify[@]}" ;;
    verify-wrong-ca) add "$n" "$host" --ssl-mode=verify --ssl-ca="$certs/wrong-ca.pem" ;;
    verify-host-mismatch) add "$n" "$lan_ip" "${verify[@]}" ;;
    verify-system) add "$n" "$host" --ssl-mode=verify ;;
    require-mtls) add "$n" "$host" --ssl-mode=require "${client[@]}" ;;
    verify-mtls) add "$n" "$host" "${verify[@]}" "${client[@]}" ;;
    missing-file)
      # add checks that the file exists (§7), so create it, register, then delete it.
      cp "$ca" "$certs/deleted-ca.pem" &&
        add "$n" "$host" --ssl-mode=verify --ssl-ca="$certs/deleted-ca.pem" &&
        rm "$certs/deleted-ca.pem"
      ;;
    *)
      echo "unknown case '$c'" >&2
      return 2
      ;;
  esac
}

mode_label() { # the "tls: …" value connection:test prints for a case
  case "$1" in
    absent | legacy-trust) echo default ;;
    disable) echo disable ;;
    require | require-mtls) echo require ;;
    *) echo verify ;;
  esac
}

total=0
failures=0

while read -r case expected override; do
  total=$((total + 1))
  # Optional 4th column "<driver>=<expected>": a per-driver deviation within a family file.
  if [ -n "$override" ]; then
    if [ "${override#*=}" = "$override" ]; then
      echo "malformed override '$override' in $family.cases (want <driver>=<expected>)" >&2
      exit 2
    fi
    case " $drivers " in
      *" ${override%%=*} "*) ;;
      *)
        echo "override '$override' in $family.cases names a driver outside the family ($drivers)" >&2
        exit 2
        ;;
    esac
    if [ "${override%%=*}" = "$driver" ]; then
      expected="${override#*=}"
    fi
  fi
  name="tls-$case"
  echo "::group::$driver / $posture / $case (expect $expected)"

  # A failed setup (connection:add or the jq rewrite) counts as a failed row, never as a skip.
  if ! setup_case "$case" "$name"; then
    echo "::endgroup::"
    echo "::error::$driver/$posture/$case: setup failed (connection:add or clonio.json edit)"
    failures=$((failures + 1))
    continue
  fi

  out="$(php clonio connection:test "$name" -v 2>&1 </dev/null)"
  code=$?
  printf '%s\nexit code: %s\n' "$out" "$code"

  want=3
  need=()
  forbid=()
  case "$expected" in
    OK) want=0 need=("tls: $(mode_label "$case")") ;;
    OK+c) want=0 need=("tls: $(mode_label "$case")" "TLS cipher:") ;;
    OK-c) want=0 need=("tls: $(mode_label "$case")") forbid=("TLS cipher:") ;;
    F) ;;
    F:req) need=("The server requires TLS") ;;
    F:tls) need=("TLS handshake failed") ;;
    F:ver) need=("TLS handshake failed. Likely causes") ;;
    F:host) need=("TLS handshake failed. Likely causes" "\"$lan_ip\"") ;;
    F:file) need=("Certificate file not found") ;;
    *)
      echo "unknown expectation '$expected' in $family.cases" >&2
      exit 2
      ;;
  esac

  ok=1
  if [ "$code" -ne "$want" ]; then
    echo "✗ exit code $code, expected $want"
    ok=0
  fi
  # ${a[@]+"${a[@]}"}: empty arrays under `set -u` on bash 3.2 (macOS rehearsal).
  for s in ${need[@]+"${need[@]}"}; do
    if ! grep -qF -- "$s" <<< "$out"; then
      echo "✗ missing: $s"
      ok=0
    fi
  done
  for s in ${forbid[@]+"${forbid[@]}"}; do
    if grep -qF -- "$s" <<< "$out"; then
      echo "✗ must not contain: $s"
      ok=0
    fi
  done
  echo "::endgroup::"

  if [ "$ok" -eq 1 ]; then
    echo "✓ $case → $expected"
  else
    echo "::error::$driver/$posture/$case expected $expected"
    failures=$((failures + 1))
  fi
done < <(awk -v p="$posture" '!/^#/ && NF && $1 == p { print $2, $3, $4 }' "$here/cases/$family.cases")

if [ "$total" -eq 0 ]; then
  echo "no cases for posture '$posture' in $family.cases" >&2
  exit 2
fi

echo "$((total - failures))/$total cases passed for $driver / $posture"
[ "$failures" -eq 0 ]
