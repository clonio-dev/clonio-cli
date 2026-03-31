# `connection:delete` Command

Deletes a saved database connection from the `clonio.json` configuration file.

## Usage

```bash
clonio connection:delete [<name>]
clonio connection:delete [<name>] --force
```

## Behaviour

1. If `<name>` is not provided and only one connection exists, it is selected automatically. If multiple connections exist, an interactive prompt asks you to choose one.
2. If the named connection does not exist, the command exits with an error.
3. A summary table of the connection is displayed before deletion. The password is always shown as `••••••••`.
4. If the connection is marked as a production connection, a warning is printed.
5. Without `--force`, a confirmation prompt is shown. The default answer is **No** — press Enter to cancel.
6. With `--force`, the confirmation is skipped and the connection is deleted immediately.
7. On success, a confirmation message is printed.

## Options

| Option | Description |
|--------|-------------|
| `--force` | Skip the confirmation prompt and delete immediately |

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | Connection deleted successfully, or deletion cancelled by the user |
| `2` | No connections exist, or the specified connection was not found |
| `5` | Write to `clonio.json` failed (permission denied or JSON encoding error) |

## Notes

- The default answer to the confirmation prompt is **No**. Pressing Enter without typing `yes` will cancel the deletion.
- If the connection has `is_production` set to `true`, a prominent warning is shown before the confirmation prompt.
- The `--force` flag is intended for scripted or non-interactive use where prompts are not desired.
