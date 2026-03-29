# Product Requirements Document
## CLI-Applikation mit Laravel Zero

**Version:** 0.1  
**Status:** Draft  
**Datum:** 2026-03-29

---

## 1. Zielsetzung

Entwicklung einer plattformübergreifenden Kommandozeilenapplikation auf Basis von **Laravel Zero** (v12), die als vollständig eigenständige Binary (kein PHP auf dem Zielsystem erforderlich) verteilbar ist. Der Build- und Release-Prozess läuft vollständig automatisiert über **GitHub Actions**.

---

## 2. Hintergrund

PHP-CLI-Tools lassen sich traditionell nur auf Systemen mit installierter PHP-Runtime ausführen. Durch den Einsatz von **Static PHP CLI (SPC)** und dem **phpmicro**-SAPI kann die Applikation als eine einzige, vollständig self-contained Binary kompiliert und ohne externe Abhängigkeiten verteilt werden.

---

## 3. Zielgruppen

- Entwickler und DevOps-Teams, die das Tool in ihre Workflows integrieren
- Endnutzer ohne PHP-Kenntnisse oder lokale PHP-Installation

---

## 4. Technologie-Stack

| Komponente | Technologie | Version |
|---|---|---|
| Sprache | PHP | 8.5 |
| Framework | Laravel Zero | 12.x |
| PHAR-Build | Laravel Zero App Builder | integriert |
| Binary-Compiler | Static PHP CLI (SPC) | aktuell stabil |
| CI/CD | GitHub Actions | — |
| Distribution | GitHub Releases | — |

---

## 5. Systemarchitektur

### 5.1 Projektstruktur

Die Applikation folgt der Standardstruktur von Laravel Zero:

```
my-tool/
├── app/
│   ├── Commands/          # Artisan Commands
│   └── Providers/
├── bootstrap/
├── config/
├── tests/
├── builds/                # Generierte PHAR-Datei
├── .github/
│   └── workflows/
│       └── build.yml      # GitHub Actions Build-Workflow
├── composer.json
└── my-tool                # Entry point
```

### 5.2 Build-Pipeline

```
Quellcode
    │
    ▼
composer install --no-dev
    │
    ▼
php artisan app:build        → builds/my-tool (PHAR)
    │
    ▼
spc download --with-php=8.5  → PHP-Quellen + Extensions
    │
    ▼
spc build --build-micro      → phpmicro.sfx (pro Plattform)
    │
    ▼
spc micro:combine            → Standalone Binary
    │
    ▼
GitHub Release Asset
```

---

## 6. Plattform-Support

| Plattform | Architektur | Unterstützt |
|---|---|---|
| Linux | x86_64 | ✅ |
| Linux | aarch64 | ✅ |
| macOS | x86_64 (Intel) | ✅ |
| macOS | aarch64 (Apple Silicon) | ✅ |
| Windows | x86_64 | ⚠️ eingeschränkt (SPC-Limitierung) |

---

## 7. PHP-Extensions (Baseline)

Die folgenden Extensions werden standardmäßig in die Binary eingebettet. Erweiterungen je nach Commands erforderlich:

```
bcmath, ctype, curl, dom, fileinfo, filter, iconv,
mbstring, openssl, pcntl, pdo, phar, posix, readline,
simplexml, tokenizer, xml, xmlreader, xmlwriter,
zip, zlib, sodium
```

---

## 8. GitHub Actions Workflow

### 8.1 Trigger

- **Push auf `main`**: Build & Test (kein Release)
- **Push eines Tags `v*`**: vollständiger Build + Release auf GitHub Releases

### 8.2 Matrix-Strategie

| Runner | Ziel-Binary |
|---|---|
| `ubuntu-latest` | `my-tool-linux-x86_64` |
| `ubuntu-24.04-arm` | `my-tool-linux-aarch64` |
| `macos-latest` | `my-tool-macos-aarch64` |
| `macos-13` | `my-tool-macos-x86_64` |

### 8.3 Workflow-Schritte (je Runner)

1. Repository auschecken
2. PHP 8.5 einrichten (`shivammathur/setup-php`)
3. Composer-Abhängigkeiten installieren (`--no-dev`)
4. PHAR-Build via `php artisan app:build`
5. SPC-Binary herunterladen
6. PHP 8.5 + Extensions kompilieren (`spc download` + `spc build --build-micro`)
7. PHAR + phpmicro zur finalen Binary kombinieren (`spc micro:combine`)
8. Binary als GitHub Release Asset hochladen

### 8.4 Caching

- Composer-Dependencies werden gecacht (`actions/cache`)
- SPC-Downloads und kompilierte PHP-Binaries werden gecacht (Cache-Key: PHP-Version + Extension-String)

---

## 9. Commands

Konkrete Commands sind noch nicht definiert. Die Hülle stellt folgende Infrastruktur bereit:

- Registrierung beliebiger Commands unter `app/Commands/`
- Automatische Erkennung durch Laravel Zero (`$commands` in `app/Providers/AppServiceProvider.php`)
- Konsistente Fehlerbehandlung über Collision-Integration
- Optionale Komponenten installierbar via `php artisan app:install` (Eloquent, Logging, HTTP Client, etc.)

---

## 10. Qualitätssicherung

| Bereich | Maßnahme |
|---|---|
| Tests | PestPHP, läuft im CI vor dem Build |
| Statische Analyse | PHPStan (Level TBD) |
| Code Style | Laravel Pint |
| Binary-Validität | Smoke-Test der generierten Binary im CI (`./my-tool --version`) |

---

## 11. Versioning & Release

- Semantic Versioning: `MAJOR.MINOR.PATCH`
- Git-Tags (`v1.0.0`) lösen den Release-Workflow aus
- GitHub Releases enthalten: alle plattformspezifischen Binaries + Changelog

---

## 12. Offene Punkte

- [ ] Projektname / Binary-Name festlegen
- [ ] Konkrete Commands definieren
- [ ] Windows-Support evaluieren (SPC-Einschränkungen prüfen)
- [ ] PHPStan-Level festlegen
- [ ] Auto-Update-Mechanismus evaluieren (Laravel Zero Phar-Updater)
