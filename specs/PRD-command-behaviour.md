# PRD — Global Command Behaviour: CI Mode, Output & Exit Codes

**Version:** 0.1
**Status:** Draft
**Date:** 2026-03-29

---

## 1. Goal

Define a consistent, cross-cutting behaviour for all Clonio commands regarding output routing, log levels, and exit codes. Any command that can run in an automated pipeline must honour the `--ci` and `--verbose` flags and produce machine-readable exit codes. This PRD is the single source of truth for these conventions and is referenced by every command PRD.

---

## 2. Scope

These rules apply to **all commands**. Commands that are explicitly designed for pipeline use (e.g. `init`, `connection:test`, future `execute`) must support `--ci`. Interactive-only commands (e.g. `connection:add`, `connection:update`, `connection:delete`) inherit the output routing and exit code rules but are not expected to be run with `--ci`.

---

## 3. Global Flags

Both flags are defined at the application level (not per command) so they are available on every command without repetition.

### 3.1 `--ci`

```
--ci    Run in CI mode: suppress all non-error output; route errors to stderr;
        exit with non-zero code on any failure.
```

- Suppresses all output at levels below `ERROR` (INFO, WARNING, DEBUG, etc.)
- `ERROR` and above are always written to **stderr**
- Enables strict exit code behaviour (see §5)
- Compatible with `--verbose`: when both are present, all levels are shown (see §4)

### 3.2 Symfony Verbosity Levels

Clonio uses Symfony Console's native verbosity flags without modification:

```
-v     VERBOSE      — show NOTICE and above
-vv    VERY_VERBOSE — show INFO and above (same as default, reserved for future use)
-vvv   DEBUG        — show all output including DEBUG-level messages
```

- No custom `--verbose` flag is defined; `-v`/`-vv`/`-vvv` are inherited from Symfony Console automatically
- In `--ci` mode: verbosity flags lift the suppression — all levels at or above the selected threshold are shown; routing rules (§4) still apply
- Does **not** affect exit codes

---

## 4. Output Routing

All output is routed based on log level, regardless of mode.

| Log level            | Channel   | Notes                                    |
|----------------------|-----------|------------------------------------------|
| `DEBUG`              | stdout    | Hidden unless `--verbose` is active      |
| `INFO`               | stdout    |                                          |
| `NOTICE`             | stdout    |                                          |
| `WARNING`            | stdout    |                                          |
| `ERROR`              | **stderr**| Always — in every mode                   |
| `CRITICAL`           | **stderr**|                                          |
| `ALERT`              | **stderr**|                                          |
| `EMERGENCY`          | **stderr**|                                          |

> The rule is: **everything below `ERROR` → stdout, `ERROR` and above → stderr**. This allows shell consumers to pipe stdout (structured results) and redirect stderr (error diagnostics) independently.

### 4.1 Interactive UI Elements

Prompts, progress bars, tables, and other interactive output are treated as `INFO`-level for routing purposes:

- Shown in normal mode
- Hidden in `--ci` mode (unless `--verbose` is also set)

---

## 5. Visible Output by Mode

| Mode          | Visible levels                                              |
|---------------|-------------------------------------------------------------|
| Normal        | INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY    |
| Normal + `-v` | NOTICE and above                                            |
| Normal + `-vvv` | DEBUG and above                                           |
| `--ci`        | ERROR, CRITICAL, ALERT, EMERGENCY only                      |
| `--ci -v`     | NOTICE and above (routing rules from §4 still apply)        |
| `--ci -vvv`   | DEBUG and above (routing rules from §4 still apply)         |

---

## 6. Exit Codes

All commands must exit with the following codes. Exit code `0` means success; any non-zero code means failure.

| Code | Meaning                                                  |
|:----:|----------------------------------------------------------|
| `0`  | Success — command completed without errors               |
| `1`  | General error — unspecified failure (catch-all)          |
| `2`  | Configuration error — `clonio.json` missing, invalid, or `APP_KEY` not set |
| `3`  | Connection error — database unreachable or authentication failed |
| `4`  | Validation error — invalid user input or argument        |
| `5`  | IO error — file permission denied, disk full, etc.       |

### 6.1 Without `--ci`

Commands always exit with the correct code, but failures are **not fatal to the shell session** in the sense that users may not notice a non-zero exit in an interactive shell if they don't inspect `$?`.

### 6.2 With `--ci`

The non-zero exit code is the **primary signal** for the CI runner. Any error — including configuration warnings that would normally be non-fatal — must cause a non-zero exit. There is no "warn and continue" in CI mode.

---

## 7. Stderr Format in CI Mode

When outputting to stderr in `--ci` mode, each line must be prefixed with the log level for easy parsing by log aggregators:

```
[ERROR] APP_KEY is not set. Run `clonio init` first.
[CRITICAL] Unexpected exception: ...
```

In normal (non-CI) mode, errors may use styled terminal output (red text, boxes, etc.) without the prefix.

---

## 8. Implementation Notes (Laravel Zero)

### 8.1 Flag Registration

Only `--ci` needs to be registered as a shared option — `-v`/`-vv`/`-vvv` are provided by Symfony Console out of the box and must not be re-declared.

```php
// Base command or mixin
protected function configure(): void
{
    $this->addOption('ci', null, InputOption::VALUE_NONE, 'Run in CI mode');
}
```

Read the active verbosity level via `$this->output->getVerbosity()` and compare against `OutputInterface::VERBOSITY_*` constants.

### 8.2 Output Helper

All commands must use a shared output helper (service or trait) rather than calling `$this->info()` / `$this->error()` directly. The helper respects the active mode and routes to stdout/stderr accordingly.

```php
// Example interface — exact implementation in SPEC
$this->output->info('Connection saved.');    // stdout, hidden in --ci
$this->output->error('APP_KEY missing.');   // stderr, always shown
$this->output->debug('Reading clonio.json'); // stdout, only with --verbose
```

### 8.3 Exit Code Constants

Define exit code constants in a shared `ExitCode` enum or class:

```php
enum ExitCode: int
{
    case Success         = 0;
    case GeneralError    = 1;
    case ConfigError     = 2;
    case ConnectionError = 3;
    case ValidationError = 4;
    case IOError         = 5;
}
```

Commands return the enum value from `handle()`: `return ExitCode::ConfigError->value;`

### 8.4 Exception Handling

Uncaught exceptions must:

1. Log the message at `ERROR` level → stderr
2. Log the stack trace at `DEBUG` level → stdout (only visible with `--verbose`)
3. Exit with code `1` (GeneralError) unless a more specific code applies

---

## 9. Examples

### Successful run in CI

```bash
$ clonio connection:test prod --ci
$ echo $?
0
```
*(no stdout output — connection OK)*

### Failed run in CI

```bash
$ clonio connection:test prod --ci
[ERROR] Connection refused: db.prod.io:5432
$ echo $?
3
```

### Failed run in CI with verbose

```bash
$ clonio connection:test prod --ci -vvv
[DEBUG] Reading clonio.json from /project/clonio.json
[DEBUG] Decrypting password for connection "prod"
[DEBUG] Attempting PDO connection to db.prod.io:5432
[ERROR] Connection refused: db.prod.io:5432
$ echo $?
3
```

### Normal interactive run

```bash
$ clonio connection:test prod

  Testing "prod" (pgsql @ db.prod.io:5432) ...  ✗  Connection refused

$ echo $?
3
```

---

## 10. Open Questions

- [ ] Should `--ci` also treat WARNING as fatal (non-zero exit)? Currently warnings are non-fatal in all modes. A `-Wall`-style strict flag could be a future addition.
- [ ] JSON output mode (`--format=json`) for structured CI output — defer to a future PRD.
