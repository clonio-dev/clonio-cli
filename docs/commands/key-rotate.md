# `key:rotate`

Replace the encryption key with a new `CLONIO_KEY` and re-encrypt every `encrypted:` secret in `clonio.json` with it.

Use it to move a project from `APP_KEY` to a dedicated `CLONIO_KEY`, or to replace a key that may have leaked.

## Usage

```
clonio key:rotate [--force]
```

## Options

| Option    | Description                              |
|-----------|------------------------------------------|
| `--force` | Rotate without asking for confirmation   |

## What It Does

1. Finds the current key the same way as [`init`](init.md): `CLONIO_KEY` first, then `APP_KEY`; the process environment wins over `.env`.
2. Generates a new key.
3. Decrypts every `encrypted:` value in `clonio.json` with the current key and encrypts it with the new key — database passwords, dump ZIP passwords and audit channel secrets alike. `env:NAME` references and plain values stay unchanged.
4. Shows a summary and asks for confirmation (default `no`).
5. Stores the new key and saves `clonio.json` (see below).

If any value cannot be decrypted with the current key, the command stops before changing anything.

### Where the new key goes

| Current key comes from | What `key:rotate` does |
|---|---|
| `.env` | Writes `CLONIO_KEY=<new key>` to `.env` (replaces the line, or appends one) and saves `clonio.json`. An `APP_KEY` line stays in `.env` for other tools. If saving `clonio.json` fails, `.env` is put back, so the key and the stored secrets always match. |
| The process environment (e.g. a CI secret) | Saves `clonio.json` and prints the new key **once**. Clonio cannot change the environment it runs in: store the new key as `CLONIO_KEY` in your secret store before the next run. |

## Examples

Moving from `APP_KEY` in `.env`:

```
$ clonio key:rotate

  Current key: APP_KEY (.env)
  Encrypted secrets in clonio.json: 3

  ⚠  Audit logs signed so far verify only with the current key.
     Keep it for as long as you need to verify them.

   Rotate the key? (yes/no) [no]:
 > yes

  ✓  Wrote the new CLONIO_KEY to .env.
  ✓  Re-encrypted 3 secrets in clonio.json.
  ℹ  Clonio now uses CLONIO_KEY. APP_KEY stays in .env for other tools;
     remove it if nothing else needs it.
```

Key from the environment, in CI:

```
$ clonio key:rotate --force

  Current key: CLONIO_KEY (environment)
  Encrypted secrets in clonio.json: 2

  ⚠  Audit logs signed so far verify only with the current key.
     Keep it for as long as you need to verify them.

  ✓  Re-encrypted 2 secrets in clonio.json.

  Store the new key as CLONIO_KEY wherever Clonio runs (e.g. your CI secret store).
  It is shown only now:

  CLONIO_KEY=base64:…
```

## Audit logs signed with the old key

Clonio signs audit logs with the encryption key. Logs signed before the rotation verify only with the old key, so keep it for as long as you need to verify them. To verify an old log, pass the old key in the process environment, which takes precedence over `.env`:

```
CLONIO_KEY='base64:<old key>' clonio cloning:verify-audit old-run_audit.html
```

Use `CLONIO_KEY` for this, even if the old key was stored as `APP_KEY`: Clonio prefers `CLONIO_KEY`, so an `APP_KEY` set on the command line is ignored once `.env` holds a `CLONIO_KEY`.

## Exit Codes

| Code | Meaning |
|------|---------|
| `0`  | Rotated, or cancelled by the user |
| `2`  | Config error — no key found, `clonio.json` is invalid, or a secret cannot be decrypted with the current key |
| `5`  | I/O error — `.env` or `clonio.json` cannot be written |

## Related Commands

- [`init`](init.md) — create a `CLONIO_KEY` for a new project
- [`cloning:verify-audit`](cloning-verify-audit.md) — verify an audit log
- [Security and deployment](../security.md)
