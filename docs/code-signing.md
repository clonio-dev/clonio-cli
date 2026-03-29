# macOS Code Signing & Notarization

Without code signing, macOS Gatekeeper blocks the binary with "cannot be scanned for malicious software". The build pipeline supports signing and notarizing automatically when the required secrets are configured.

## Workaround (unsigned builds)

If the secrets are not configured, the binary is still produced but unsigned. Users can bypass Gatekeeper manually:

```bash
xattr -d com.apple.quarantine clonio-macos-aarch64
chmod +x clonio-macos-aarch64
./clonio-macos-aarch64 --version
```

## Prerequisites

- An [Apple Developer account](https://developer.apple.com/programs/) ($99/year)
- A **Developer ID Application** certificate issued to your account

## One-time setup

### 1. Export the certificate as a .p12 file

In Keychain Access on your Mac:
1. Find your **Developer ID Application** certificate under *My Certificates*
2. Right-click → Export → choose `.p12` format
3. Set a strong password — you will need it for the GitHub secret

### 2. Base64-encode the certificate

```bash
base64 -i certificate.p12 | pbcopy
```

### 3. Create an app-specific password for notarization

Go to [appleid.apple.com](https://appleid.apple.com) → Sign-In and Security → App-Specific Passwords → Generate one for "GitHub Actions".

### 4. Add GitHub repository secrets

Go to **Settings → Secrets and variables → Actions** and add:

| Secret | Value |
|--------|-------|
| `APPLE_DEVELOPER_CERTIFICATE_P12` | Base64-encoded `.p12` from step 2 |
| `APPLE_DEVELOPER_CERTIFICATE_PASSWORD` | Password set during `.p12` export |
| `APPLE_SIGNING_IDENTITY` | Full identity string, e.g. `Developer ID Application: Your Name (TEAMID)` |
| `APPLE_ID` | Your Apple ID email address |
| `APPLE_ID_PASSWORD` | App-specific password from step 3 |
| `APPLE_TEAM_ID` | Your 10-character team ID, visible at [developer.apple.com/account](https://developer.apple.com/account) |

### Finding your signing identity

```bash
security find-identity -v -p codesigning
```

Copy the full string in quotes, e.g. `Developer ID Application: Acme Corp (ABC123XYZ)`.

## How it works in CI

The three signing steps in `build.yml` are conditional on `APPLE_DEVELOPER_CERTIFICATE_P12` being set. If the secret is absent the steps are skipped and an unsigned binary is produced — the rest of the pipeline (smoke test, upload) still runs.

When the secret is present:
1. The certificate is imported into a temporary keychain
2. `codesign` signs the binary with the hardened runtime flag (`--options runtime`) — required for notarization
3. `notarytool` submits the binary to Apple for notarization and waits for approval
4. The notarized binary is uploaded to the GitHub Release
