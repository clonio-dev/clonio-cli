# PRD — `connection:delete` Command

**Version:** 0.1
**Status:** Draft
**Date:** 2026-03-29

---

## 1. Goal

Remove a named database connection permanently from `clonio.json`.

---

## 2. Command Signature

```
connection:delete
    {name?           : Name of the connection to delete}
    {--force         : Skip confirmation prompt}
```

---

## 3. Interactive Flow

1. **Name selection** — if `name` argument is omitted, the user selects from a list of existing connections
2. Display a summary of the connection to be deleted (same format as `connection:add` success output, password shown as `••••••••`)
3. If the connection is marked `is_production: true`, show a prominent warning (yellow/red) before the confirmation
4. Confirm: "Delete connection '<name>'? This cannot be undone. [yes/no]" — defaults to `no`
5. Remove the entry from `clonio.json` and write the file

If `--force` is passed, steps 2–4 are skipped and the entry is deleted immediately.

---

## 4. Error Cases

| Situation                        | Behaviour                                            |
|----------------------------------|------------------------------------------------------|
| `name` does not exist            | Exit with error                                      |
| No connections in config file    | Exit with error; suggest `connection:add`            |
| `clonio.json` missing or invalid | Exit with error; show parse error or path            |
| User answers `no` at confirmation | Exit cleanly without writing; display "Cancelled"   |

---

## 5. Out of Scope

- Deleting multiple connections in one call
- Archiving / soft-delete
