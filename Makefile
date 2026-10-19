.DEFAULT_GOAL := help
.PHONY: help current preflight patch minor major alpha docker-build docker-build-multiarch docker-test docker-shell

DOCKER_IMAGE ?= clonio:local
# Commit verified by `make preflight`. patch/minor/major tag this exact commit,
# not HEAD, so switching branches in this checkout during the preflight is safe.
RELEASE_SHA_FILE := $(shell git rev-parse --git-path clonio-release-sha 2>/dev/null)

# ──────────────────────────────────────────────────────────────────────────────
# "latest stable" excludes pre-release tags (anything with a `-` suffix like
# v0.6.8-alpha.1). Those are test builds and shouldn't be used as the bump base.
# ──────────────────────────────────────────────────────────────────────────────

help:
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	PATCH=$$(echo "$$VERSION" | cut -d. -f3); \
	NEXT_PATCH="v$$MAJOR.$$MINOR.$$((PATCH+1))"; \
	echo ""; \
	echo "  Clonio CLI — Release Tagging"; \
	echo ""; \
	echo "  Current stable: $$CURRENT"; \
	echo ""; \
	echo "  Commands:"; \
	printf "    %-12s %s\n" "make current" "Show current stable version on main"; \
	printf "    %-12s %s\n" "make preflight" "Dry-run the release build on main for all targets (patch/minor/major run this first)"; \
	printf "    %-12s %s\n" "make alpha" "Tag a pre-release ($$CURRENT → $$NEXT_PATCH-alpha.N) — CI builds a GitHub pre-release, no Packagist ping"; \
	printf "    %-12s %s\n" "make patch" "Bump patch version  ($$CURRENT → $$NEXT_PATCH)"; \
	printf "    %-12s %s\n" "make minor" "Bump minor version  ($$CURRENT → v$$MAJOR.$$((MINOR+1)).0)"; \
	printf "    %-12s %s\n" "make major" "Bump major version  ($$CURRENT → v$$((MAJOR+1)).0.0)"; \
	echo ""; \
	echo "  Docker (local image: $(DOCKER_IMAGE)):"; \
	printf "    %-26s %s\n" "make docker-build" "Build image for host arch (fast)"; \
	printf "    %-26s %s\n" "make docker-build-multiarch" "Build image for linux/amd64 + linux/arm64 (slow, QEMU)"; \
	printf "    %-26s %s\n" "make docker-test" "Build image then run tests/smoke/run-smoke.sh against it"; \
	printf "    %-26s %s\n" "make docker-shell" "Drop into a shell inside the image (debug mounts)"; \
	echo ""

current:
	@git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1 || echo "v0.0.0"

# ──────────────────────────────────────────────────────────────────────────────
# Preflight: before tagging, run the build workflow as a dry-run on origin/main
# and wait for it to pass on all targets. The dry-run also stores the SPC caches
# on main, which the tag run restores — so the release builds from exactly what
# was just verified instead of downloading fresh upstream sources.
# Skipped when a green dry-run for origin/main exists and the SPC build caches
# are still present. Bypass with SKIP_PREFLIGHT=1 (tags origin/main as fetched).
# The verified commit is written to $(RELEASE_SHA_FILE) for the tag targets.
# ──────────────────────────────────────────────────────────────────────────────

preflight:
	@set -e; \
	rm -f "$(RELEASE_SHA_FILE)"; \
	git fetch -q origin main --tags; \
	MAIN=$$(git rev-parse origin/main); SHORT=$$(git rev-parse --short origin/main); \
	if [ -n "$(SKIP_PREFLIGHT)" ]; then echo "Preflight skipped (SKIP_PREFLIGHT set) — will tag origin/main $$SHORT."; echo "$$MAIN" > "$(RELEASE_SHA_FILE)"; exit 0; fi; \
	command -v gh >/dev/null || { echo "gh CLI is required for the preflight."; exit 1; }; \
	GREEN=$$(gh run list --workflow build.yml --branch main -L 30 --json headSha,conclusion,event \
	  --jq "[.[] | select(.headSha == \"$$MAIN\" and .conclusion == \"success\" and .event != \"push\")] | length"); \
	CACHES=$$(gh cache list --ref refs/heads/main --key spc-build- -L 20 --json key --jq 'length'); \
	if [ "$$GREEN" -gt 0 ] && [ "$$CACHES" -ge 3 ]; then echo "Preflight: dry-run for $$SHORT already green and SPC caches are warm."; echo "$$MAIN" > "$(RELEASE_SHA_FILE)"; exit 0; fi; \
	echo "Preflight: starting dry-run build for $$SHORT (all targets, ~15–40 min)..."; \
	gh workflow run build.yml --ref main; \
	RUN=""; for i in 1 2 3 4 5 6 7 8 9 10 11 12; do \
	  sleep 5; \
	  RUN=$$(gh run list --workflow build.yml --branch main --event workflow_dispatch -L 5 --json databaseId,headSha,status \
	    --jq "[.[] | select(.headSha == \"$$MAIN\" and .status != \"completed\")][0].databaseId // empty"); \
	  [ -n "$$RUN" ] && break; \
	done; \
	if [ -z "$$RUN" ]; then echo "Preflight: could not find the dispatched run."; exit 1; fi; \
	echo "Preflight: watching https://github.com/clonio-dev/clonio-cli/actions/runs/$$RUN"; \
	if ! gh run watch "$$RUN" --exit-status --interval 30 >/dev/null; then \
	  echo "Preflight FAILED — not tagging. Inspect: gh run view $$RUN --log-failed"; exit 1; \
	fi; \
	echo "$$MAIN" > "$(RELEASE_SHA_FILE)"; \
	echo "Preflight: all targets built and smoke-tested for $$SHORT."

# ──────────────────────────────────────────────────────────────────────────────

patch: preflight
	@set -e; \
	CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	PATCH=$$(echo "$$VERSION" | cut -d. -f3); \
	NEW="v$$MAJOR.$$MINOR.$$((PATCH+1))"; \
	SHA=$$(cat "$(RELEASE_SHA_FILE)" 2>/dev/null) || { echo "No verified commit — run make preflight."; exit 1; }; \
	echo "Tagging $$NEW on $$(git rev-parse --short $$SHA)..."; \
	git tag "$$NEW" "$$SHA"; \
	git push origin "$$NEW" || { git tag -d "$$NEW" >/dev/null; exit 1; }; \
	echo "Done — $$NEW pushed. CI will build the release automatically."

minor: preflight
	@set -e; \
	CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	NEW="v$$MAJOR.$$((MINOR+1)).0"; \
	SHA=$$(cat "$(RELEASE_SHA_FILE)" 2>/dev/null) || { echo "No verified commit — run make preflight."; exit 1; }; \
	echo "Tagging $$NEW on $$(git rev-parse --short $$SHA)..."; \
	git tag "$$NEW" "$$SHA"; \
	git push origin "$$NEW" || { git tag -d "$$NEW" >/dev/null; exit 1; }; \
	echo "Done — $$NEW pushed. CI will build the release automatically."

major: preflight
	@set -e; \
	CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	NEW="v$$((MAJOR+1)).0.0"; \
	SHA=$$(cat "$(RELEASE_SHA_FILE)" 2>/dev/null) || { echo "No verified commit — run make preflight."; exit 1; }; \
	echo "Tagging $$NEW on $$(git rev-parse --short $$SHA)..."; \
	git tag "$$NEW" "$$SHA"; \
	git push origin "$$NEW" || { git tag -d "$$NEW" >/dev/null; exit 1; }; \
	echo "Done — $$NEW pushed. CI will build the release automatically."

# ──────────────────────────────────────────────────────────────────────────────
# Pre-release: tags the next patch version as v$NEXT_PATCH-alpha.N, where N
# auto-increments based on existing alpha tags for that target. Marked as a
# GitHub pre-release by CI; Packagist is NOT notified.
# ──────────────────────────────────────────────────────────────────────────────

alpha:
	@set -e; \
	STABLE=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	STABLE=$${STABLE:-v0.0.0}; \
	VERSION=$$(echo "$$STABLE" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	PATCH=$$(echo "$$VERSION" | cut -d. -f3); \
	TARGET="v$$MAJOR.$$MINOR.$$((PATCH+1))"; \
	COUNT=$$(git tag --list "$$TARGET-alpha.*" | wc -l | tr -d ' '); \
	NEW="$$TARGET-alpha.$$((COUNT+1))"; \
	echo "Tagging $$NEW..."; \
	git tag "$$NEW"; \
	git push origin "$$NEW" || { git tag -d "$$NEW" >/dev/null; exit 1; }; \
	echo "Done — $$NEW pushed. CI will build a pre-release automatically."

# ──────────────────────────────────────────────────────────────────────────────
# Docker — source-based image (php:8.5-cli-alpine + composer install). Same
# Dockerfile is consumed locally and in CI; no static-binary dependency, so the
# image can be built before / in parallel with the binary build.
# ──────────────────────────────────────────────────────────────────────────────

docker-build:
	@echo "Building $(DOCKER_IMAGE) for host arch..."
	docker build -t $(DOCKER_IMAGE) .
	@echo "Done — try: docker run --rm -v \"$$(pwd)\":/workspace $(DOCKER_IMAGE) --version"

docker-build-multiarch:
	@echo "Building $(DOCKER_IMAGE) for linux/amd64 + linux/arm64 (QEMU emulation)..."
	docker buildx build --platform linux/amd64,linux/arm64 -t $(DOCKER_IMAGE) .

docker-test: docker-build
	@echo "Running smoke test against $(DOCKER_IMAGE)..."
	./tests/smoke/run-smoke.sh docker $(DOCKER_IMAGE)

docker-shell: docker-build
	docker run --rm -it -v "$$(pwd)":/workspace --entrypoint sh $(DOCKER_IMAGE)
