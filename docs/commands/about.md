# `about` Command

Displays a short introduction to Clonio — who it is for, what it does, and where to learn more.

## Usage

```bash
clonio about
```

## Output

Prints the Clonio ASCII logo (with drop shadow) followed by a brief product description.

## Notes

- No arguments or options are accepted.
- The logo is read from `resources/ascii-art/clonio-logo-with-shadow.txt` at runtime.
- If the asset file is missing the logo is silently skipped; the description text is still printed.
- Exit code is always `0`.
