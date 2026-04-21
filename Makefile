.DEFAULT_GOAL := help
.PHONY: help current patch minor major alpha

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
	echo ""

current:
	@git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1 || echo "v0.0.0"

# ──────────────────────────────────────────────────────────────────────────────

patch:
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	PATCH=$$(echo "$$VERSION" | cut -d. -f3); \
	NEW="v$$MAJOR.$$MINOR.$$((PATCH+1))"; \
	echo "Tagging $$NEW..."; \
	git tag "$$NEW"; \
	git push origin "$$NEW"; \
	echo "Done — $$NEW pushed. CI will build the release automatically."

minor:
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	NEW="v$$MAJOR.$$((MINOR+1)).0"; \
	echo "Tagging $$NEW..."; \
	git tag "$$NEW"; \
	git push origin "$$NEW"; \
	echo "Done — $$NEW pushed. CI will build the release automatically."

major:
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	NEW="v$$((MAJOR+1)).0.0"; \
	echo "Tagging $$NEW..."; \
	git tag "$$NEW"; \
	git push origin "$$NEW"; \
	echo "Done — $$NEW pushed. CI will build the release automatically."

# ──────────────────────────────────────────────────────────────────────────────
# Pre-release: tags the next patch version as v$NEXT_PATCH-alpha.N, where N
# auto-increments based on existing alpha tags for that target. Marked as a
# GitHub pre-release by CI; Packagist is NOT notified.
# ──────────────────────────────────────────────────────────────────────────────

alpha:
	@STABLE=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | grep -v -- '-' | head -1); \
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
	git push origin "$$NEW"; \
	echo "Done — $$NEW pushed. CI will build a pre-release automatically."
