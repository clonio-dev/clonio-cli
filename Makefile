.DEFAULT_GOAL := help
.PHONY: help current patch minor major

# ──────────────────────────────────────────────────────────────────────────────

help:
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	PATCH=$$(echo "$$VERSION" | cut -d. -f3); \
	echo ""; \
	echo "  Clonio CLI — Release Tagging"; \
	echo ""; \
	echo "  Current version: $$CURRENT"; \
	echo ""; \
	echo "  Commands:"; \
	printf "    %-12s %s\n" "make current" "Show current version on main"; \
	printf "    %-12s %s\n" "make patch" "Bump patch version  ($$CURRENT → v$$MAJOR.$$MINOR.$$((PATCH+1)))"; \
	printf "    %-12s %s\n" "make minor" "Bump minor version  ($$CURRENT → v$$MAJOR.$$((MINOR+1)).0)"; \
	printf "    %-12s %s\n" "make major" "Bump major version  ($$CURRENT → v$$((MAJOR+1)).0.0)"; \
	echo ""

current:
	@git tag --sort=-version:refname --merged origin/main 2>/dev/null | head -1 || echo "v0.0.0"

# ──────────────────────────────────────────────────────────────────────────────

patch:
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | head -1); \
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
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | head -1); \
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
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	NEW="v$$((MAJOR+1)).0.0"; \
	echo "Tagging $$NEW..."; \
	git tag "$$NEW"; \
	git push origin "$$NEW"; \
	echo "Done — $$NEW pushed. CI will build the release automatically."
