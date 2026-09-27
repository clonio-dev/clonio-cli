#!/usr/bin/env bash
# Starts one database server in one TLS posture (PRD-connection-tls §10.1) as container "clonio-tls-db".
# Usage: start-server.sh <mysql|mariadb|pgsql|sqlsrv> <posture> <certs-dir>
set -euo pipefail

driver="$1"
posture="$2"
certs="$(cd "$3" && pwd)"
name=clonio-tls-db
# CLONIO_TLS_PORT overrides the published host port (local rehearsal next to a native server).

wait_for() { # wait_for <what> <command...>
  local what="$1"
  shift
  for _ in $(seq 1 90); do
    if "$@" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  echo "timeout waiting for $what" >&2
  docker logs "$name" 2>&1 | tail -50 >&2
  exit 1
}

unknown_posture() {
  echo "unknown posture '$posture' for $driver" >&2
  exit 2
}

# A config directory the container user can read (mktemp -d is 0700).
conf_dir() {
  local dir
  dir="$(mktemp -d)"
  chmod 755 "$dir"
  echo "$dir"
}

case "$driver" in
  mysql | mariadb)
    if [ "$driver" = mysql ]; then
      image=mysql:8.4 env=MYSQL cli=mysql plain=(--tls-version=)
    else
      image=mariadb:11 env=MARIADB cli=mariadb plain=(--skip-ssl)
    fi
    tls=(--ssl-ca=/certs/ca.pem --ssl-cert=/certs/server.pem --ssl-key=/certs/server-key.pem)
    case "$posture" in
      plain) args=("${plain[@]}") ;;
      tls) args=("${tls[@]}") ;;
      tls-required | mtls-required) args=("${tls[@]}" --require-secure-transport=ON) ;;
      *) unknown_posture ;;
    esac

    docker run -d --name "$name" -p "${CLONIO_TLS_PORT:-3306}:3306" \
      -e "${env}_ROOT_PASSWORD=secret" -e "${env}_DATABASE=clonio_test" \
      -v "$certs:/certs:ro" "$image" "${args[@]}"

    # The init phase runs a temporary server without networking ("port: 0"). Only the final
    # server logs "port: 3306". The X plugin's "port: 33060" must not match.
    wait_for "$image" sh -c "docker logs $name 2>&1 | grep -Eq 'port: 3306([^0-9]|\$)'"

    if [ "$posture" = mtls-required ]; then
      # root@localhost (socket) stays usable for this statement; the matrix connects as root@'%'.
      docker exec "$name" "$cli" -uroot -psecret -e "ALTER USER 'root'@'%' REQUIRE X509; FLUSH PRIVILEGES;"
    fi
    ;;

  pgsql)
    if [ "$posture" = plain ]; then
      docker run -d --name "$name" -p "${CLONIO_TLS_PORT:-5432}:5432" \
        -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=clonio_test postgres:16
    else
      case "$posture" in
        tls) hba='host all all all scram-sha-256' ;;
        tls-required) hba=$'hostnossl all all all reject\nhostssl all all all scram-sha-256' ;;
        mtls-required) hba=$'hostnossl all all all reject\nhostssl all all all scram-sha-256 clientcert=verify-ca' ;;
        *) unknown_posture ;;
      esac
      conf="$(conf_dir)"
      # "local … trust" keeps the image's init scripts working over the socket.
      printf 'local all all trust\n%s\n' "$hba" > "$conf/pg_hba.conf"
      chmod 644 "$conf/pg_hba.conf"

      # PostgreSQL refuses a server key that is not owned by postgres with mode 0600, so copy the
      # certs inside the container before handing over to the stock entrypoint.
      docker run -d --name "$name" -p "${CLONIO_TLS_PORT:-5432}:5432" \
        -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=clonio_test \
        -v "$certs:/certs-src:ro" -v "$conf:/etc/clonio:ro" \
        --entrypoint bash postgres:16 -c '
          set -e
          install -d -o postgres -g postgres /certs
          install -o postgres -g postgres -m 600 /certs-src/ca.pem /certs-src/server.pem /certs-src/server-key.pem /certs/
          exec docker-entrypoint.sh postgres -c ssl=on \
            -c ssl_ca_file=/certs/ca.pem -c ssl_cert_file=/certs/server.pem -c ssl_key_file=/certs/server-key.pem \
            -c hba_file=/etc/clonio/pg_hba.conf'
    fi

    # The init server listens on the socket only, so TCP readiness means the final server is up.
    # pg_isready reports "accepting connections" even when pg_hba would reject it.
    wait_for postgres docker exec "$name" pg_isready -h 127.0.0.1 -U postgres
    ;;

  sqlsrv)
    image=mcr.microsoft.com/mssql/server:2022-latest
    run=(docker run -d --name "$name" -p "${CLONIO_TLS_PORT:-1433}:1433" -e ACCEPT_EULA=Y -e "MSSQL_SA_PASSWORD=Clonio@Strong1" -e MSSQL_PID=Express)
    case "$posture" in
      self-signed)
        "${run[@]}" "$image"
        ;;
      tls | tls-required)
        if [ "$posture" = tls-required ]; then force=1; else force=0; fi
        conf="$(conf_dir)"
        printf '[network]\ntlscert = /certs/server.pem\ntlskey = /certs/server-key.pem\ntlsprotocols = 1.2\nforceencryption = %s\n' \
          "$force" > "$conf/mssql.conf"
        chmod 644 "$conf/mssql.conf"
        "${run[@]}" -v "$certs:/certs:ro" -v "$conf/mssql.conf:/var/opt/mssql/mssql.conf" "$image"

        # The sqlsrv `verify` mode (and ODBC 18's default) use the OS trust store (§5.3).
        sudo cp "$certs/ca.pem" /usr/local/share/ca-certificates/clonio-test-ca.crt
        sudo update-ca-certificates
        ;;
      *) unknown_posture ;;
    esac

    wait_for "SQL Server" docker exec "$name" /opt/mssql-tools18/bin/sqlcmd \
      -S localhost -U sa -P 'Clonio@Strong1' -Q 'SELECT 1' -b -C

    if [ "$posture" != self-signed ]; then
      # Without this check a rejected test cert silently falls back to the self-generated one and the
      # matrix would test the wrong posture. SQL Server 2022 logs:
      #   The certificate [Certificate File:'/certs/server.pem', Private Key File:'/certs/server-key.pem'] was successfully loaded for encryption.
      loaded=0
      for _ in $(seq 1 15); do
        if docker logs "$name" 2>&1 | grep -F "Certificate File:'/certs/server.pem'" | grep -qF 'successfully loaded for encryption'; then
          loaded=1
          break
        fi
        sleep 2
      done
      if [ "$loaded" -ne 1 ]; then
        echo "SQL Server did not load /certs/server.pem for encryption" >&2
        docker logs "$name" 2>&1 | tail -80 >&2
        exit 1
      fi
    fi
    ;;

  *)
    echo "unknown driver '$driver'" >&2
    exit 2
    ;;
esac

echo "$driver ($posture) is up"
