# PRD — `init` Command

**Version:** 0.1
**Status:** Draft
**Date:** 2026-03-29

---

## 1. Goal

Bootstrap a Clonio project in the current working directory by ensuring a valid `APP_KEY` is available for encryption. In production environments the key is expected to come from the system environment; on developer machines a local `.env` file is generated automatically.

---

## 2. Command Signature

```
init
    {--force         : Regenerate APP_KEY even if one already exists}
```

---

## 3. Key Resolution Order

Clonio looks for `APP_KEY` in this order:

1. **System environment variable** — `APP_KEY` already set in the shell / process environment
2. **Local `.env` file** — `.env` in the current working directory

If a valid key is found in either location, initialisation is considered complete and no file is written.

---

## 4. Interactive Flow

```
$ clonio init

  Checking for APP_KEY ...

  ✓  APP_KEY found in environment — no .env file needed.
```

or

```
$ clonio init

  Checking for APP_KEY ...

  No APP_KEY found. Generating .env with a new key ...

  ✓  Created .env with APP_KEY in /path/to/project
```

or (key found only in `.env`):

```
$ clonio init

  Checking for APP_KEY ...

  ✓  APP_KEY found in .env — ready.
```

### 4.1 `--force` flag

When `--force` is passed and an `APP_KEY` is already present:

```
  APP_KEY already exists. --force was passed — regenerating.

  ⚠  Warning: regenerating the key will make all existing encrypted
     passwords in clonio.json unreadable. You will need to re-enter
     them via `connection:update`.

  Regenerate key? [yes/no] (no)
```

Defaults to `no`. If confirmed, a new key is generated and the `.env` file is updated (or created).

---

## 5. APP_KEY Format

- Generated with Laravel's `Str::random(32)` wrapped in the standard Laravel prefix: `base64:<base64-encoded-32-byte-random-string>`
- Identical to what `php artisan key:generate` produces, making the value usable with `Crypt::encryptString()`

---

## 6. `.env` File Handling

### 6.1 File does not exist

Create `.env` in `cwd` with the following minimal content:

```dotenv
APP_KEY=base64:...
```

Set file permissions to `0600` (owner read/write only).

### 6.2 File exists but has no `APP_KEY`

Append `APP_KEY=base64:...` to the existing file. Do not touch other entries.

### 6.3 File exists and already has `APP_KEY`

Do nothing (unless `--force` is passed).

### 6.4 `.gitignore` hint

After creating or updating `.env`, check whether `.gitignore` exists in `cwd`. If it does and `.env` is not already listed, display a notice:

```
  ℹ  Remember to add .env to your .gitignore to avoid committing your APP_KEY.
```

Clonio does **not** modify `.gitignore` automatically.

---

## 7. Production Environments

In production, `APP_KEY` should be injected as a system environment variable (e.g. via Docker secrets, Kubernetes secrets, CI/CD environment variables). In that case:

- `init` completes immediately (key found in env, step 1 of resolution order)
- No `.env` file is created
- The user can confirm readiness by running `init` explicitly if desired

---

## 8. Relation to Other Commands

- `connection:add`, `connection:update`, `connection:test` all require `APP_KEY` to be available. If it is missing they exit with an error and suggest running `clonio init`.
- `init` does not create `clonio.json` — that is the responsibility of `connection:add`.

---

## 9. Error Cases

| Situation                                    | Behaviour                                                  |
|----------------------------------------------|------------------------------------------------------------|
| No write permission in `cwd`                 | Exit with error; show path and permission hint             |
| `.env` exists but is not readable            | Exit with error; show path                                 |
| Key generation fails (no entropy source)     | Exit with error; unlikely in practice                      |
| `--force` confirmed but write fails          | Exit with error; original file left unchanged              |

---

## 10. Out of Scope

- Global `~/.clonio/.env` (one key for all projects) — revisit in a future PRD if requested
- Rotating / re-encrypting all passwords in `clonio.json` after a key change (separate `key:rotate` command, future)
- Validating that an existing `APP_KEY` is correctly formatted
