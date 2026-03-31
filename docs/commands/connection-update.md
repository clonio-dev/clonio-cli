# `connection:update` Command

Interactively updates an existing database connection stored in `clonio.json`.

## Usage

```bash
clonio connection:update [<name>]
```

The `name` argument is optional. When omitted, Clonio selects the connection automatically if only one exists, or presents a choice prompt when multiple connections are configured.

## Interactive flow

1. **Select connection** — If `name` is not provided and multiple connections exist, a choice prompt lists all connection names. If only one connection exists it is selected automatically.
2. **Edit fields** — All fields are presented with their current values pre-filled as defaults. Press `Enter` to keep a value unchanged.
3. **Driver change** — If the database driver is changed to a type with a different set of required fields (e.g. switching from MySQL to SQLite), fields that no longer apply are dropped and new required fields are prompted without defaults.
4. **Password** — The existing password is never decrypted or shown. A blank response keeps the current encrypted value. Entering a new password encrypts it before saving.
5. **Review changes** — A diff table shows only the fields that changed, with `Old` and `New` columns. If the password changed it is shown as `••••••••` in both columns.
6. **Confirm save** — A confirmation prompt (`Save changes? [Y/n]`) defaults to yes. Answering no cancels without writing any changes.

## Exit codes

| Code | Constant | Meaning |
|------|----------|---------|
| `0` | `Success` | Connection updated successfully, or save cancelled by user |
| `2` | `ConfigError` | Connection not found, or no connections exist in `clonio.json` |
| `4` | `ValidationError` | New name conflicts with an existing connection |
| `5` | `IoError` | Failed to write `clonio.json` |

## Notes

**Name change** — If the connection name is changed to a value not already in use, the old entry is removed and the new one is written atomically via `renameConnection`. If the new name is already taken the command exits with code `4` before writing anything.

**Driver change** — Changing the driver type resets network fields (host, port) and optional fields (schema) that are not applicable to the new driver. The user is prompted for all required fields of the new driver without pre-filled defaults.

**Password handling** — Passwords are stored encrypted. The update command never exposes the stored ciphertext. Leaving the password prompt blank preserves the existing encrypted value unchanged.
