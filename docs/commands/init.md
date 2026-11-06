# `init`

Bootstrap Clonio in the current working directory by ensuring an encryption key (`CLONIO_KEY`) is available.

## Usage

```
clonio init [--force]
```

## Options

| Option    | Description                                               |
|-----------|-----------------------------------------------------------|
| `--force` | Generate a new `CLONIO_KEY` even if a key already exists  |

## What It Does

Clonio encrypts the `encrypted:` secrets in `clonio.json` and signs audit logs with its encryption key. `init` looks for the key in this order:

1. **`CLONIO_KEY`** — in the process environment, then in `.env` in the current working directory
2. **`APP_KEY`** — the same places, as a fallback for projects set up with earlier versions of Clonio

A value in the process environment wins over the same variable in `.env`. If a key is found, `init` writes no key.

If no key is found, `init` generates a new `CLONIO_KEY` and writes it to `.env` in the current directory. File permissions are set to `0600` (owner read/write only).

Clonio uses its own variable so that it does not share a key with a Laravel application in the same directory. To move an existing project from `APP_KEY` to `CLONIO_KEY`, run [`key:rotate`](key-rotate.md).

## Examples

Key already set in the shell:

```
$ clonio init

  Checking for CLONIO_KEY ...
  ✓  CLONIO_KEY found in environment — no .env file needed.
```

No key found — writes `.env`:

```
$ clonio init

  Checking for CLONIO_KEY ...
  Writing a new CLONIO_KEY to .env ...

  ✓  Wrote CLONIO_KEY to .env in /path/to/project
```

Key found in `.env`:

```
$ clonio init

  Checking for CLONIO_KEY ...
  ✓  CLONIO_KEY found in .env — ready.
```

Only `APP_KEY` found:

```
$ clonio init

  Checking for CLONIO_KEY ...
  ✓  APP_KEY found in .env — ready.
  ℹ  Clonio uses APP_KEY because CLONIO_KEY is not set. Run `clonio key:rotate`
     to move to a dedicated CLONIO_KEY.
```

## Force Regeneration

When `--force` is passed and a key already exists, Clonio warns that existing encrypted secrets in `clonio.json` will become unreadable:

```
$ clonio init --force

  Checking for CLONIO_KEY ...
  CLONIO_KEY already exists. --force was passed — regenerating.

  ⚠  Warning: a new key makes all existing encrypted secrets in
     clonio.json unreadable. To keep them, run `clonio key:rotate`
     instead.

   Regenerate key? (yes/no) [no]:
```

Defaults to `no`. If confirmed, a new `CLONIO_KEY` is written to `.env`. An `APP_KEY` line stays untouched.

## `.env` File Handling

| Scenario                                | Behaviour                                                       |
|-----------------------------------------|-----------------------------------------------------------------|
| `.env` does not exist                   | Creates `.env` with `CLONIO_KEY=base64:...`                     |
| `.env` exists, no key                   | Appends `CLONIO_KEY=base64:...` without touching other entries  |
| `.env` exists with `CLONIO_KEY` or `APP_KEY` | Does nothing (unless `--force` is passed)                  |
| `--force` confirmed                     | Replaces the `CLONIO_KEY` line, or appends one; `APP_KEY` stays |

### `.gitignore` Management

After any successful initialisation, Clonio checks whether `.env` and `clonio.json` are protected by `.gitignore`. Both files contain secrets (the encryption key and encrypted database passwords) and must never be committed.

**When `.gitignore` exists:** Clonio automatically appends any missing entries and reports what it added:

```
  ✓  Added to .gitignore: clonio.json
  ✓  Added to .gitignore: .env
```

If both entries are already present, nothing is printed and the file is not modified.

**When no `.gitignore` exists:** Clonio prints a note without creating the file:

```
  ℹ  No .gitignore found. Add .env and clonio.json to avoid committing secrets.
```

This check runs on every `init` invocation — regardless of whether a key was newly generated or already present.

## Production Environments

In production, set `CLONIO_KEY` as a system environment variable (Docker secrets, Kubernetes secrets, CI/CD env vars). `init` completes immediately without writing any file.

Better still, store no encrypted secrets at all: use `env:NAME` references for passwords and tokens (the default in `connection:add` and `audit:add`) and provide the values from your secret store. Then the key protects nothing of value, and a copy of `.env` plus `clonio.json` reveals no credentials. See [Security and deployment](../security.md).

## Relation to Other Commands

- `connection:add`, `connection:update`, `connection:test`, and the audit commands need the encryption key to encrypt and decrypt stored secrets. Secrets stored as `env:NAME` references do not use it. Run `clonio init` once per project directory before using those commands.
- [`key:rotate`](key-rotate.md) replaces the key and re-encrypts the stored secrets.
- When `clonio.json` has no audit channels yet, `init` creates it with the default channels `local`, `stdout` and `stderr`.

## Exit Codes

| Code | Meaning                                       |
|------|-----------------------------------------------|
| `0`  | Success (key found or `.env` written)         |
| `5`  | I/O error (cannot write `.env`)               |
