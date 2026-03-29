# PRD — `connection:update` Command

**Version:** 0.1
**Status:** Draft
**Date:** 2026-03-29

---

## 1. Goal

Allow users to update an existing named database connection. All current values are pre-filled in the prompts so the user only changes what is needed.

---

## 2. Command Signature

```
connection:update
    {name?           : Name of the connection to update}
```

No option flags — all editing happens interactively with pre-filled values.

---

## 3. Interactive Flow

1. **Name selection** — if `name` argument is omitted, the user selects from a list of existing connections; if only one connection exists it is selected automatically
2. For the selected connection, each field is presented **in the same order as `connection:add`**, with the stored value pre-filled:
   1. **Name** — current name shown; can be changed (must remain unique)
   2. **Type** — choice list with current driver highlighted
   3. **Host** — skipped for `sqlite`; pre-filled with current value
   4. **Port** — skipped for `sqlite`; pre-filled with current value
   5. **Database** — pre-filled with current value
   6. **Schema** — pgsql only; pre-filled with current value
   7. **Username** — skipped for `sqlite`; pre-filled with current value
   8. **Password** — skipped for `sqlite`; shown as `••••••••`; user can press Enter to keep the existing password unchanged, or type a new one
   9. **Is production?** — yes / no; pre-filled with current value
3. After all prompts, display a summary diff (old value → new value) for every changed field
4. Confirm: "Save changes? [yes/no]" — defaults to `yes`
5. Write updated entry back to `clonio.json`

---

## 4. Driver Change Handling

If the user changes the `type` (e.g. from `mysql` to `sqlite`):

- Fields that are not applicable to the new driver (host, port, username, password, schema) are removed from the stored entry
- Fields newly required by the new driver are prompted fresh (no pre-fill possible)
- The port default is reset to the new driver's default before prompting

---

## 5. Name Change Handling

If the user changes the `name`:

- The old key is removed from `connections` and the new key is inserted
- If the new name already exists, exit with an error before writing

---

## 6. Password Handling

- The existing encrypted value is **never decrypted** for display — the field always shows `••••••••`
- If the user presses Enter without typing a new password, the existing encrypted value is preserved as-is
- If the user types a new password, it is encrypted with `Crypt::encryptString()` before storage

---

## 7. Validation

Same rules as `connection:add` (see PRD-connection-add §6). Applied after the user completes all prompts, before the confirmation step.

---

## 8. Error Cases

| Situation                        | Behaviour                                             |
|----------------------------------|-------------------------------------------------------|
| `name` does not exist            | Exit with error; suggest `connection:add`             |
| No connections in config file    | Exit with error; suggest `connection:add`             |
| New name conflicts with existing | Exit with error; re-prompt for name                   |
| `clonio.json` missing or invalid | Exit with error; show parse error or path             |
| User cancels at confirmation     | Exit cleanly without writing; no changes persisted    |

---

## 9. Out of Scope

- Batch-updating multiple connections at once
- Updating via option flags (non-interactive mode)
