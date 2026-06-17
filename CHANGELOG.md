# Changelog

All notable changes to `clonio-cli` will be documented in this file.

## v0.9.0 - 2026-06-17

## What's Changed
* chore(deps-dev): bump driftingly/rector-laravel from 2.3.0 to 2.4.0 by @dependabot[bot] in https://github.com/clonio-dev/clonio-cli/pull/126
* chore(deps): bump symfony/polyfill-intl-idn from 1.37.0 to 1.38.1 by @dependabot[bot] in https://github.com/clonio-dev/clonio-cli/pull/127
* chore(deps): bump symfony/yaml from 8.0.12 to 8.1.0 by @dependabot[bot] in https://github.com/clonio-dev/clonio-cli/pull/128
* chore(deps-dev): bump larastan/larastan from 3.9.6 to 3.10.0 by @dependabot[bot] in https://github.com/clonio-dev/clonio-cli/pull/129
* chore(deps): bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/clonio-dev/clonio-cli/pull/125
* chore(deps-dev): bump laravel/pao from 1.0.6 to 1.1.1 by @dependabot[bot] in https://github.com/clonio-dev/clonio-cli/pull/134
* feat: Add PRD for dump connection type (issue #124) by @rokde in https://github.com/clonio-dev/clonio-cli/pull/136
* Support dump by @rokde in https://github.com/clonio-dev/clonio-cli/pull/137


**Full Changelog**: https://github.com/clonio-dev/clonio-cli/compare/v0.8.0...v0.9.0

## v0.8.0 - 2026-05-22

## What's changed

- test(audit): cover Flysystem-based S3 delivery and endpoint handling (5fad851)
- refactor(audit): deliver S3 audit artefacts via Flysystem adapter instead of hand-rolled SigV4 cURL (5dbed85)
- docs(cloning): document template strategy in schema and PII matcher specs (ef69859)
- test(cloning): cover template strategy expansion and validation (ba3d98e)
- feat(cloning): add template anonymization strategy with {fakerMethod} placeholders (4ccc25b)
- test(pii): update matchers:check expectations for new baseline strategies (0332709)
- refactor(pii): replace hash/mask baseline matchers with fake, static and nullify strategies (5a9a73c)
- docs: document optional hash salt, per-run random salt and GDPR pseudonymization guidance (484d0a1)
- test(cloning): cover per-run random salt behavior and optional salt validation (4fb4c36)
- feat(cloning): apply per-run random salt for hash strategy when salt omitted (f34b174)
- docs: document audit.use active channel list replacing audit.default (7407a9d)
- refactor(logging): bind AuditBuffer singleton and merge clonio.json logging overrides (b316c0c)
- refactor(audit): replace audit.default and deliver_to with audit.use channel list (2b6e106)
- refactor(logging): replace RunLogWriter with centralized AuditBuffer logging system (2bf072d)
- chore: replace abandoned nunomaduro/pao with laravel/pao (0eaadcc)
- chore: bump composer dependencies to latest patch and minor releases (55dfedf)
- docs: add example for pulling and running Docker image in workflow (84a9d80)
- docs: document automatic loopback host rewrite for Docker connections (cfd4ab5)
- feat: rewrite loopback hosts to host.docker.internal when running in Docker (486499c)
- docs: document source-based image internals and host mounting (f24d88c)
- chore: strip unused mpdf font families from vendor in CI build (3393b65)
- feat: build Docker image from source with multi-stage php:8.5-cli-alpine (d2e202b)
- chore(deps): bump setasign/fpdi from 2.6.6 to 2.6.7 (a95c625)
- chore(deps): bump symfony/yaml from 8.0.8 to 8.0.11 (ca15fd4)
- chore(deps-dev): bump pestphp/pest from 4.6.3 to 4.7.0 (2b2530c)
- chore(deps-dev): bump nunomaduro/pao from 1.0.4 to 1.0.6 (a0057b1)
- chore(deps): bump docker/metadata-action from 5 to 6 (5ad692a)

## v0.7.7 - 2026-04-28

## What's changed

- feat(cloning:table:edit): new command to edit table row strategy and clear mode (1f2ea7f)
- feat(audit): add Summary & Integrity recap with source/target connection details (8b964d6)
- feat(cloning:run): graceful recovery for KeyRemappingExhaustedException (d9ee92e)
- feat(cloning:column:edit): support remapping strategy with auto-detected FKs (30eee2a)

## v0.7.6 - 2026-04-27

## What's changed

- fix(build): pin libcares + direct tarball URL to bypass SPC release-metadata lookup (00cdce8)

## v0.7.5 - 2026-04-27 (tag only)

### What's Changed

* chore(deps): bump docker/setup-buildx-action from 3 to 4 (9dfd241)
* fix(cloning): purge legacy range knobs from CI fixture + user docs (8c4bb76)
* fix(audit): page-break before Integrity heading in PDF output (b0f76d5)
* feat(cloning): auto-bounds for key remapping (drop user range knobs) (7f0cbff)
* chore(deps): bump dependabot/fetch-metadata from 3.0.0 to 3.1.0 (fcf5f4f)
* chore(deps): bump docker/build-push-action from 6 to 7 (dc4a430)
* chore(deps-dev): bump laravel/pint from 1.29.0 to 1.29.1 (390dead)
* chore(deps): bump docker/login-action from 3 to 4 (dd524f0)
* chore(deps-dev): bump nunomaduro/pao from 0.1.7 to 1.0.4 (67c1153)
* chore(deps): bump docker/setup-qemu-action from 3 to 4 (4e05a9e)

**Full Changelog**: https://github.com/clonio-dev/clonio-cli/compare/v0.7.4...v0.7.5

## v0.7.4 - 2026-04-26

## What's changed

- docs(specs): clarify VerboseStepRenderer renders no animated spinner (261b0d8)
- fix(cloning): preserve captured skip rows on outer-catch + truthful schema-diff failure indicator (6384f4f)
- fix(cloning): re-guard schema diff to verbose mode + tighten verbose-mode tests (037a588)
- feat(cloning): wire verbose step renderer + render grouped skip reasons under tables (055fd10)
- test(cloning): cover cascade-skip path and sharpen onTableStart contract (cf9ec73)
- feat(cloning): add onTableStart callback + extend onProgress with skippedRows (a8f5e0c)
- test(cloning): tighten skip-row PK assertions and remove unused counter (16e4dde)
- feat(cloning): capture per-row skip details with chunk offset, pk snapshot, and SQL error (24adbb9)
- fix(output): collapse padding dots cleanly at overflow + assert clear-line escape (78413d5)
- feat(output): add VerboseStepRenderer for start-then-finalize step output (1c30fe3)
- feat(cloning): add SkippedRow value object for per-row insert failures (d6929bc)
- docs(plans): implementation plan for verbose logging + skip details (9e073cf)
- docs(specs): verbose logging rework + per-row skip details (4b47eaf)

## v0.7.3 - 2026-04-22

## What's changed

- fix(ci): restrict release artifact download to binaries + phar (4e79178)

## v0.7.2 - 2026-04-22

## What's changed

- ci: functional smoke against every release artifact + gate release on docker (02e1895)
- test(smoke): shared functional smoke script for binaries / phar / docker (ffb9f5d)
- fix(audit): set mpdf tempDir to OS temp so PDF works inside PHAR (989b757)
- docs: implementation plan for mpdf fix + binary smoke tests (559e104)
- docs: spec for mpdf/phar fix + per-artifact binary smoke tests (9705b77)

## v0.7.1-alpha.2 - 2026-04-21 (pre-release)

## What's changed

- ci: create release as draft, then publish to work around immutable releases (40bf6af)
- ci: key SPC caches by matrix.output to prevent arch collision (3e4c71f)
- ci(docker): tag workflow_dispatch builds with dev-<sha> (21880b5)
- docs(releasing): document Docker publishing stage (952cec9)
- docs(readme): add Docker install channel (746e2f1)
- docs: fix binary artifact names in Docker internals section (7cc0dba)
- docs: add Docker distribution guide (9f32185)
- docs(release): surface Docker channel in GitHub Release notes (8db4ef1)
- ci(docker): add GHCR multi-arch image publishing job (09e3328)
- feat(docker): add multi-arch Dockerfile (alpine base, static binary) (a69f40f)
- chore(docker): add .dockerignore limiting build context to bin/ + Dockerfile (e18b60f)
- docs: add Docker release channel implementation plan (#84) (91e4d06)
- docs: add Docker release channel design spec (#84) (ce6d743)

## v0.7.1 - 2026-04-22

## What's changed

## v0.7.0 - 2026-04-21

## What's changed

- refactor: add logging & audit refactor implementation plan for symfony + laravel (1d24496)
- ci: fix SPC build (onig via mbregex) and add test-release tooling (31fbc82)

## v0.6.7 - 2026-04-20

## What's changed

- fix: resolve PHPStan errors in delivery adapters — type-safe config access, pure SMTP (f60a3ee)
- docs: update verbosity levels and audit config documentation (e439e1b)
- refactor: update audit CRUD commands for default + stack pattern (0bda590)
- refactor: simplify InitCommand defaults — audit.default + local path ./ (0189682)
- refactor: restructure audit config to default + stack pattern (f0da6f1)
- feat: implement S3, Email, Webhook, and ntfy delivery adapters (7f4d182)
- refactor: introduce DeliveryAdapterInterface, update existing adapters (989ee03)
- feat: add Stack channel type to AuditChannelType enum (16fd085)
- refactor: implement verbosity levels — dots (default), text (-v), live log (-vv) (dbf4753)
- feat: add live-output callback to RunLogWriter for -vv streaming (2c81441)
- refactor: remove Monolog logging config — output goes through Console (718078e)
- ci: add oniguruma library to SPC build for mbregex support (2792c0f)
- chore(deps-dev): bump pestphp/pest from 4.5.0 to 4.6.3 (e78ef06)
- chore(deps-dev): bump larastan/larastan from 3.9.5 to 3.9.6 (ff07340)

## v0.6.6 - 2026-04-15

## What's changed

- ci: fix cache key invalid characters by replacing comma-separated GD_LIBS with hyphenated slug (3822a35)

## v0.6.5 - 2026-04-15

## What's changed

- ci: improve cache keys for rector/phpstan and fix Storage type safety (9c91b9c)

## v0.6.4 - 2026-04-15 (tag only)

### What's Changed

* chore: remove PHPStan ignored error for ColumnEditCommand (5ba17d1)
* style: redesign audit log HTML/PDF with branded layout, KPI cards, and improved typography (0f4c5ec)
* ci: add JPEG, WebP, and FreeType support to GD in SPC build for PDF rendering (fca7a64)
* ci: conditionally enable SPC debug flag based on runner debug mode (b79cdb2)

**Full Changelog**: https://github.com/clonio-dev/clonio-cli/compare/v0.6.3...v0.6.4

## v0.6.3 - 2026-04-15

## What's changed

- ci: pin OpenSSL to 3.6.2 in SPC build to fix PHP 8.5 compilation (a1d24b5)

## v0.6.2 - 2026-04-15

## What's changed

- ci: add debug flag to SPC build and upload logs on failure (4cc4f23)

## v0.6.1 - 2026-04-15

## What's changed

- chore: bypass phpstan (514876a)
- chore: reverted (97ee8c2)
- chore: added gd lib for mpdf (9979084)
- chore: added type (bb73892)
- chore: removed comment (d1e9cc0)
- feat: added PDF rendering (8a69730)
- feat: added mpdf dependency (c5d8a51)
- chore: linted (49c03e6)
- feat: memory exception now hints original call with tha flag (2929091)
- feat: added cause of an skipping (b346bc1)
- Delete .github/workflows/claude.yml (dcf7e76)
- Add GitHub Actions workflow for Claude Code (1df554d)

## v0.6.0 - 2026-04-13

## What's changed

- docs: document YAML-level table skipping (skip: list and rows.strategy: skip) (1af8c96)
- fix: apply yaml skipTables on dry-run path; replace redundant orchestrator test with feature test (47d8dbd)
- feat: merge config->skipTables with CLI --skip-tables in RunCommand (069acc1)
- feat: support rows.strategy skip and validate top-level skip: in YAML (cb22054)
- feat: add skipTables to CloningConfigData and parse top-level skip: list (09aac93)
- docs: add implementation plan for YAML-level table skipping (61882d9)
- docs: add spec for YAML-level table skipping (#64) (d6b54bc)
- chore: bump composer dependencies to latest versions (9cdd654)
- style: add declare(strict_types=1) to bootstrap and config files (Rector) (57326be)
- docs: add schema replication robustness implementation plan (a8dda32)
- fix: prevent ON UPDATE clause from being partially consumed by FK regex (c8fdf3b)
- style: fix Pint/Rector lint issues in SchemaReplicator and its tests (1662e89)
- docs: document --break-on-failure flag and skipped_by_schema_failure status (ecc08ff)
- feat: add --break-on-failure flag and SkippedBySchemaFailure output to cloning:run (c33665e)
- feat: handle schema failures, break-on-failure, and AUTO_INCREMENT correction in orchestrator (68edfce)
- fix: resolve PHPStan type error in correctAutoIncrement (6c69538)
- feat: add correctAutoIncrement() to SchemaReplicator (4934069)
- feat: add SkippedBySchemaFailure to TableRunStatus (e548ea4)
- feat: per-table schema fallback in replicate() with array<string,string> return (87eb60d)
- fix: make FK-stripping regex explicit to handle ON DELETE/UPDATE clauses (7ab6e42)
- fix: strip entire FOREIGN KEY line including REFERENCES clause in native DDL sanitiser (aee3934)
- chore: add .worktrees to .gitignore (aa65c45)
- docs: add design spec for schema replication robustness (c2a9f10)
- chore(deps-dev): bump driftingly/rector-laravel from 2.2.0 to 2.3.0 (b14e2aa)
- chore(deps-dev): bump larastan/larastan from 3.9.3 to 3.9.4 (a4842b0)
- chore(deps-dev): bump pestphp/pest-plugin-type-coverage (dfb982a)
- chore(deps): bump softprops/action-gh-release from 2 to 3 (6553dd5)

## v0.5.2 - 2026-04-09

## What's changed

- docs: update init, cloning:dump, and cloning:run for #48 and #51 (a8bc5f2)
- feat: ask for transfer options at dump time, allow overrides at run time (#51) (25eb757)
- feat: auto-append .env and clonio.json to .gitignore on init (#48) (75039f7)

## v0.5.1 - 2026-04-09

## What's changed

- docs: update docs for all merged features (#49, #52, #53, #54) (a4ba813)
- fix: apply Pint binary_operator_spaces fix to AuditDeliveryServiceTest (8720e1d)
- refactor: introduce KeyRemappingStoreInterface with in-memory default (659312c)
- fix: update AddCommand to use renamed defaultDeliversProcessLog() (f3c2db1)
- fix: apply Rector/Pint suggestions to key remapping store (ef5aa53)
- fix: apply Pint style fixes to ColumnEditCommand (0f85b05)
- fix: update AuditChannelTypeTest to use renamed defaultDeliversProcessLog() (d6cdc48)
- fix: apply Rector suggestions to SchemaReplicator (525e9f7)
- feat: add --no-memory-limit and --file-based flags to cloning:run (428a1c7)
- feat: show faker argument hints in cloning:column:edit (2e30d9e)
- fix: separate audit and process log delivery per channel type (b62f9d1)
- fix: expand type mapping and add native DDL for same-DB schema replication (f6612f9)
- fix: add trailing newline to about command output (fddfe18)
- feat: add keywords to composer.json for improved package discoverability (1cc72a5)
- feat: add nunomaduro/pao dependency for enhanced PHP testing output (694d48a)
- fix: update website URL from clonio.io to clonio.dev in AboutCommand and README (4f13332)
- feat: add JSON schema for Clonio CLI configuration validation (e7da687)

## v0.5.0 - 2026-04-08

## What's changed

- fix: add --no-verify-ssl flag and optional version argument to update command (f2b4d2d)
- feat: add cloning:column:edit command for interactive strategy editing (3dcedb4)
- fix: skip N:M pivot tables with composite PKs from key remapping (f8a81b6)
- fix: filter production connections from interactive target selection (dcd9e91)
- fix: display audit log duration in HH:MM:SS,mmm format (d29a1e5)
- fix: exclude primary key from column list when key remapping is active (ff80d69)
- refactor(commands): unify `local-audit-log-path` and `local-run-log-path` into `local-path` option (3e3bb9c)
- chore: update .gitignore to exclude clonio.json (1622161)
- feat: add Clonio logo with claim output and update commands to use it (452a9f3)

## v0.4.2 - 2026-04-08

## What's changed

- chore(docs): add documentation for `connection:list` command detailing usage and behavior (fa3bbc5)
- chore(docs): enhance composer distribution documentation and add Packagist integration details (4bfd837)

## v0.4.1 - 2026-04-08

## What's changed

- chore(docs): update composer bin path and restructure PHP version requirement section (d97082e)
- chore(docs): update composer distribution docs to reflect CI-based PHAR builds (29fac91)
- chore: consolidate release documentation into a single file and update for CI workflow changes (ca3203e)
- chore: add PHP setup and PHAR build steps to GitHub workflow (393031e)
- chore: remove deprecated `phar-build` GitHub workflow (02a2b9f)
- chore: streamline release process by removing version file updates and main branch pushes (eef2ef4)
- chore: simplify `.gitignore` by consolidating build directory rules (33f7218)
- chore: remove obsolete `clonio` build file (2d72b32)
- chore: mark version as unreleased in VERSION and composer.json (7f3d484)
- chore: update phar build [skip ci] (fc7709a)
- refactor(commands): remove the dependency to termwind and DOMDocument for that (83da4a5)
- chore: update phar build [skip ci] (8600af3)
- feat(init): enhance audit channel creation feedback (c85c06c)
- chore: update phar build [skip ci] (4b44af1)
- Add funding information to FUNDING.yml (2168264)
- chore: update phar build [skip ci] (d511b21)
- bump: update version to 0.4.0 in composer.json (177b5df)
- chore: update phar build [skip ci] (8bbb4d8)
- chore: update phar build [skip ci] (7c47f68)

## v0.4.0 - 2026-04-05

## What's changed

- chore: release v0.4.0 (29ff3a6)
- docs: add .cloning.yaml format reference and link from README (414decd)
- docs: document schema synchronization options and schema diff (6fcc775)
- chore: update phar build [skip ci] (1c00267)
- feat(schema): add drop_extra_columns option for schema synchronization (9876bb8)
- feat(schema): add schema diff between source and target (b86601f)
- chore: update phar build [skip ci] (7ae9719)
- feat: add --all-columns flag to cloning:dump (2634af2)
- chore: update phar build [skip ci] (bd37910)
- fix: ensure KeyRemappingTableData type check in CloningYamlWriter and update related tests (9d60104)
- fix: update DumpCommand tests and suppress remapping in --only-pii mode (84f1d28)
- docs: document inline strategy: remapping with foreign_keys example (c57b470)
- feat: support strategy: remapping as inline column definition (f090e81)
- chore: update phar build [skip ci] (193037f)
- chore: update phar build [skip ci] (3f661a0)
- feat: write default audit channels (local/stdout/stderr) on clonio init (2448004)
- fix: pin SPC to v2.8.4 to resolve musl-toolchain download failure (3ff86be)
- chore: update phar build [skip ci] (3973f8b)
- ci: automate phar build on main push, simplify release workflow (7a4dcc5)
- chore(deps-dev): bump pestphp/pest from 4.4.3 to 4.4.5 (2bca599)
- chore(deps): bump actions/download-artifact from 4 to 8 (5c1ac79)
- chore(deps): bump actions/upload-artifact from 4 to 7 (8b16118)

## v0.3.2 - 2026-04-03

## What's changed

- chore: release v0.3.2 (f44b37c)
- chore: fix fallback logic for capturing first insert error in cloning workflow (4f97f19)
- chore: capture first insert error in cloning workflow and include in failure reason (8673ef3)
- chore: handle all rows failing during insert in cloning workflow and update related tests (7dd6688)
- chore: update `clear` default value to `delete` in cloning YAML writer and documentation (9bbf62b)
- chore: add `ClearMode` enum and integrate `rows.clear` handling across cloning workflow with tests and documentation (6676321)
- refactor: remove `readonly` properties in fake data-related classes and simplify collection handling (7eca899)
- docs: mark fake data-related classes with `@codeCoverageIgnore` (152c23b)
- docs: add documentation for `fake:data` command, detailing usage, schema, and performance (f2c26de)
- chore: add `fake:data` command with schema creation, seeding logic, and supporting generator classes (fd17c1f)
- chore: add support for detecting and displaying disabled matchers in `matchers:check` command with tests (c54eb6c)
- chore: add `PiiSensitivity` enum and integrate sensitivity levels into matchers, commands, and documentation (392e4d5)
- chore: enhance `matchers:check` command to support user-provided values and document examples with transformations (d46256e)
- chore: add support for user-provided values in `matchers:check` command with extensive test coverage (dd5f3fe)
- chore: add optional `exampleValue` field to `PiiMatcherData` (7b5430c)
- chore: add `exampleValue` field to `PiiMatcherData` for baseline entries (fa8c4db)
- chore: add test for `trust_server_certificate` handling in `ConnectionData` serialization (117a7d3)
- chore: update `CloningYamlWriter` to use `KeyRemappingConfigData` for key remapping checks (2349a63)
- chore: rename `pii-matchers.yaml` to `clonio.pii-matchers.yaml` across commands, docs, and tests (4c7bb8f)
- chore: add key remapping configuration support to cloning dump command and YAML writer (a018fa9)
- chore: remove `cloning` namespace from matcher commands and update test references (41f628a)
- chore: simplify matcher command signatures by removing `cloning` prefix (36a021a)
- chore: add seeding and factory commands to commands configuration (7b81a95)
- chore: refactor matcher list display to use table format (795638d)
- chore: add migration commands to commands configuration (fa98d9b)
- chore: make pkg-config installation optional in macOS build workflow (067fb98)

## v0.3.1 - 2026-04-02

## What's changed

- chore: release v0.3.1 (03d5dfd)
- docs: document `connection:list` command in README.md (b53022e)
- chore: move FORCE_JAVASCRIPT_ACTIONS_TO_NODE24 to global env in build workflow (69a900e)
- chore: add pkg-config installation step to macOS build workflow (2417a4c)

## v0.3.0 - 2026-04-02

## What's changed

- chore: release v0.3.0 (92af0f7)
- docs: add badges for connection and cloning run tests in README.md (76d2970)
- chore: simplify SQL Server installation in cloning-run-test workflow (38c2c5b)
- feat: add SQL Server support in cloning tests workflow (62c1892)
- feat: add schema for cloning:run CI tests on SQL Server (f3a5929)
- feat: implement UUID key remapping with referential integrity validation (b5e1111)
- chore: automate build, version tagging, and release in Makefile (c434ac4)
- chore: remove unused PHP setup and build steps from GitHub Actions workflow (7431e1c)
- refactor: enhance git.version resolution fallback logic (7c77fc2)
- chore: add VERSION file with initial content (966b87f)
- feat: add `connection:list` command to display configured database connections (50bf5bf)
- refactor: standardize option name prefixes in `audit:add` command (7a1a6d1)
- update: refine .gitignore to exclude all /builds/* except /builds/clonio (379f2b5)
- feat: add audit channel CRUD commands (5f9214b)
- feat: implement key remapping in cloning:run (f6623be)
- docs: add composer distribution guide and config changes (7e93ac7)
- docs: add PRD for key remapping in cloning pipeline (ae1dc82)
- docs: add PRD for audit channel management commands (b331316)
- config: disable requirement checks in box.json (562314f)

## v0.2.0 - 2026-04-01

## What's changed

- fix: remove aligned arrow spacing to satisfy pint (5faba58)
- fix: remove redundant use statements for built-in DateTime classes in audit tests (48aae3f)
- fix: handle SQLite in buildConfig to avoid 'hosts array is empty' error (44f460f)
- fix: remove unused quoteIdentifier call and apply rector/pint fixes (56bc999)
- docs: add documentation for cloning commands and update README (4b3c58e)
- ci: add cloning:run end-to-end test workflow with multi-DBMS matrix (e66272b)
- feat: implement cloning:run, audit system, and cloning:verify-audit commands (b8e13fb)
- feat: implement cloning:dump command and core cloning infrastructure (e9c3c80)
- feat: implement cloning:matchers commands and PII matcher system (2de4b1d)
- Add PRDs for Audit Log Delivery and Cloning Dump features (a95332e)

## v0.1.3 - 2026-03-31

## What's changed

- Update README with new database connection management commands and `init` command (f5f3499)
- Add support for `trustServerCertificate` in SQL Server connections (1a25c01)
- Refactor `TestCommand` to use `DatabaseConnectionService` for password decryption and dynamic connection management (8dfcbd1)
- Add `DatabaseConnectionService` with dynamic connection management and password decryption (5f8186e)
- Add charset and collation configuration based on database type in `TestCommand` (3e1042f)
- Install Microsoft ODBC Driver 18 in connection test workflow (042ea2e)
- Inject ASCII art in `init` command using `AsciiArtService` (148a2f5)
- Add support for custom indentation in ASCII art rendering (3b7ff41)
- Use `Env::get` for retrieving `APP_KEY` instead of `$_ENV` (1be6032)
- Load environment variables in `AppServiceProvider` during registration (45013e7)
- Add `init` command to generate and manage `APP_KEY` (50e0ab5)
- Add documentation for `init` command (7619d4d)
- Update `.gitignore` to exclude Laravel testing cache files (22392aa)
- Refactor `ConfigService` to utilize `Storage` facade for file operations (9c3b15a)
- Add filesystem/storage documentation and best practices (56efeca)
- Add GitHub Actions workflow for connection tests (01b3890)
- Add documentation for all connection-related commands (af8fd78)
- Add `ConfigService` for managing database connection configurations (26db001)
- Add `connection:add` command to create new database connections (cc01f5d)
- Add `connection:test` command to validate database connections (64305c1)
- Add `connection:delete` command to remove database connections (c1243e7)
- Add `connection:update` command to update existing database connections (e8b2a37)
- Add `DatabaseConnectionType` enum for managing database connection types and attributes (901ee8d)
- Add `ExitCode` enum for standardizing application exit codes (0bf6855)
- Add `ConnectionData` DTO and unit tests for database connection handling (86879a0)
- Add `EncryptionServiceProvider` and configure `APP_KEY` for testing in PHPUnit (8af16d8)
- Add `illuminate/encryption` package to composer dependencies (fc81089)
- Expand CLAUDE.md with documentation structure and locations for specs and docs (a1177df)
- Expand CLAUDE.md with companion repository details and implementation guidance (76ff02e)
- Add CLAUDE.md with developer guidance and architecture overview (a6f7b84)
- Update .gitignore to exclude log files in /storage/logs (9e0e667)

## v0.1.2 - 2026-03-29

## What's changed

- Add PRDs for `clonio.json` configuration, database connection workflow, and global command behavior (de603f4)
- Fix incorrect fallback constant for MySQL SSL attribute in database config (ab365db)
- Refactor log file path determination logic in `AppServiceProvider` for better Phar runtime checks (2edadc6)
- Add `.env.example` and update `.gitignore` to exclude `.env` file (b0dabc6)

## v0.1.1 - 2026-03-29 (tag only)

### What's Changed

* Refactor Makefile to improve version detection logic (8cb824a)
* Add filesystem configuration (dd8aec7)
* Add logging configuration and update dependencies (e3c75fd)
* Add database configuration and update dependencies (de4b5e0)

**Full Changelog**: https://github.com/clonio-dev/clonio-cli/compare/v0.1.0...v0.1.1

## v0.1.0 - 2026-03-29

## What's changed

- Update build workflow to include `session` extension and bump cache keys to v2 (ea246e6)
- Add Makefile for streamlined release version bumping and tagging (c16e46f)
- Refactor `UpdateCommand` to improve message formatting and simplify exception handling (77b5892)
- Refactor `BinaryResolver` for improved readability and error handling (cc55b50)
- Improve error handling in `update` command and adjust unit test coverage threshold (bdbce1b)
- Add `update` command to check for and install latest release with tests included (a003b8f)
- Add `BinaryResolver` service and unit tests to resolve and validate binary paths (e1e1f36)
- Update dependencies in `composer.lock` to include new library versions (311b61b)
- Move `AboutCommandTest` to correct namespace directory (2558846)
- Update test suite to include Unit tests directory in Pest configuration (5dce42d)
- Document `update` command and remove obsolete example test (61c2f79)

## v0.0.6 - 2026-03-29 (tag only)

### What's Changed

* Update build workflow to include `session` extension and bump cache keys to v2 (ea246e6)
* Add Makefile for streamlined release version bumping and tagging (c16e46f)
* Refactor `UpdateCommand` to improve message formatting and simplify exception handling (77b5892)
* Refactor `BinaryResolver` for improved readability and error handling (cc55b50)
* Improve error handling in `update` command and adjust unit test coverage threshold (bdbce1b)
* Add `update` command to check for and install latest release with tests included (a003b8f)
* Add `BinaryResolver` service and unit tests to resolve and validate binary paths (e1e1f36)
* Update dependencies in `composer.lock` to include new library versions (311b61b)
* Move `AboutCommandTest` to correct namespace directory (2558846)
* Update test suite to include Unit tests directory in Pest configuration (5dce42d)
* Document `update` command and remove obsolete example test (61c2f79)

**Full Changelog**: https://github.com/clonio-dev/clonio-cli/compare/v0.0.5...v0.0.6

## v0.0.5 - 2026-03-29

## What's changed

- Update PHAR artifact upload step in GitHub Actions workflow to include `.phar` extension (f79d7f9)
