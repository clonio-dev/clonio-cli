# `init`

Bootstrap Clonio in the current working directory by ensuring `APP_KEY` is available for encryption.

## Usage

```
clonio init [--force]
```

## Options

| Option    | Description                                          |
|-----------|------------------------------------------------------|
| `--force` | Regenerate `APP_KEY` even if one already exists      |

## What It Does

`init` checks for an `APP_KEY` in the following order:

1. **System environment variable** — `APP_KEY` already set in the shell / process environment
2. **Local `.env` file** — `.env` in the current working directory

If a valid key is found, initialisation is complete and no file is written.

If no key is found, a new key is generated and written to `.env` in the current directory. File permissions are set to `0600` (owner read/write only).

## Examples

Key already set in the shell:

```
$ clonio init

  Checking for APP_KEY ...

  ✓  APP_KEY found in environment — no .env file needed.
```

No key found — creates `.env`:

```
$ clonio init

  Checking for APP_KEY ...

  No APP_KEY found. Generating .env with a new key ...

  ✓  Created .env with APP_KEY in /path/to/project
```

Key found in `.env`:

```
$ clonio init

  Checking for APP_KEY ...

  ✓  APP_KEY found in .env — ready.
```

## Force Regeneration

When `--force` is passed and a key already exists, Clonio warns that existing encrypted passwords in `clonio.json` will become unreadable:

```
$ clonio init --force

  APP_KEY already exists. --force was passed — regenerating.

  ⚠  Warning: regenerating the key will make all existing encrypted
     passwords in clonio.json unreadable. You will need to re-enter
     them via `connection:update`.

  Regenerate key? [yes/no] (no)
```

Defaults to `no`. If confirmed, the `.env` file is updated with the new key.

## `.env` File Handling

| Scenario                             | Behaviour                                              |
|--------------------------------------|--------------------------------------------------------|
| `.env` does not exist                | Creates `.env` with `APP_KEY=base64:...`               |
| `.env` exists, no `APP_KEY` line     | Appends `APP_KEY=base64:...` without touching other entries |
| `.env` exists with `APP_KEY`         | Does nothing (unless `--force` is passed)              |

### `.gitignore` Hint

After writing `.env`, if a `.gitignore` exists in the current directory but does not include `.env`, Clonio displays a reminder:

```
  ℹ  Remember to add .env to your .gitignore to avoid committing your APP_KEY.
```

Clonio does **not** modify `.gitignore` automatically.

## Production Environments

In production, set `APP_KEY` as a system environment variable (Docker secrets, Kubernetes secrets, CI/CD env vars). `init` completes immediately without writing any file.

## Relation to Other Commands

- `connection:add`, `connection:update`, and `connection:test` all require `APP_KEY` to be present for encrypting and decrypting passwords. Run `clonio init` once per project directory before using those commands.
- `init` does not create `clonio.json`. That file is created by `connection:add`.

## Exit Codes

| Code | Meaning                                       |
|------|-----------------------------------------------|
| `0`  | Success (key found or `.env` written)         |
| `5`  | I/O error (cannot write `.env`)               |
