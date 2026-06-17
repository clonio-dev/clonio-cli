.DEFAULT_GOAL := help
.PHONY: help current patch minor major alpha docker-build docker-build-multiarch docker-test docker-shell

DOCKER_IMAGE ?= clonio:local

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

patch:
	@set -e; \
	CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	PATCH=$$(echo "$$VERSION" | cut -d. -f3); \
	NEW="v$$MAJOR.$$MINOR.$$((PATCH+1))"; \
	echo "Tagging $$NEW..."; \
	git tag "$$NEW"; \
	git push origin "$$NEW" || { git tag -d "$$NEW" >/dev/null; exit 1; }; \
	echo "Done — $$NEW pushed. CI will build the release automatically."

minor:
	@set -e; \
	CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	NEW="v$$MAJOR.$$((MINOR+1)).0"; \
	echo "Tagging $$NEW..."; \
	git tag "$$NEW"; \
	git push origin "$$NEW" || { git tag -d "$$NEW" >/dev/null; exit 1; }; \
	echo "Done — $$NEW pushed. CI will build the release automatically."

major:
	@set -e; \
	CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	NEW="v$$((MAJOR+1)).0.0"; \
	echo "Tagging $$NEW..."; \
	git tag "$$NEW"; \
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
