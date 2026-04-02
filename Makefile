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

define release
	@NEW=$(1); \
	echo "Building PHAR for $$NEW..."; \
	echo "$$NEW" > VERSION; \
	php clonio app:build clonio --no-interaction; \
	git add VERSION builds/clonio; \
	git commit -m "chore: release $$NEW"; \
	git tag "$$NEW"; \
	git push origin main; \
	git push origin "$$NEW"; \
	echo "Done — $$NEW pushed. The build pipeline will start automatically."
endef

patch:
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	PATCH=$$(echo "$$VERSION" | cut -d. -f3); \
	NEW="v$$MAJOR.$$MINOR.$$((PATCH+1))"; \
	echo "Building PHAR for $$NEW..."; \
	echo "$$NEW" > VERSION; \
	php clonio app:build clonio --no-interaction; \
	git add VERSION builds/clonio; \
	git commit -m "chore: release $$NEW"; \
	git tag "$$NEW"; \
	git push origin main; \
	git push origin "$$NEW"; \
	echo "Done — $$NEW pushed. The build pipeline will start automatically."

minor:
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	MINOR=$$(echo "$$VERSION" | cut -d. -f2); \
	NEW="v$$MAJOR.$$((MINOR+1)).0"; \
	echo "Building PHAR for $$NEW..."; \
	echo "$$NEW" > VERSION; \
	php clonio app:build clonio --no-interaction; \
	git add VERSION builds/clonio; \
	git commit -m "chore: release $$NEW"; \
	git tag "$$NEW"; \
	git push origin main; \
	git push origin "$$NEW"; \
	echo "Done — $$NEW pushed. The build pipeline will start automatically."

major:
	@CURRENT=$$(git tag --sort=-version:refname --merged origin/main 2>/dev/null | head -1); \
	CURRENT=$${CURRENT:-v0.0.0}; \
	VERSION=$$(echo "$$CURRENT" | sed 's/^v//'); \
	MAJOR=$$(echo "$$VERSION" | cut -d. -f1); \
	NEW="v$$((MAJOR+1)).0.0"; \
	echo "Building PHAR for $$NEW..."; \
	echo "$$NEW" > VERSION; \
	php clonio app:build clonio --no-interaction; \
	git add VERSION builds/clonio; \
	git commit -m "chore: release $$NEW"; \
	git tag "$$NEW"; \
	git push origin main; \
	git push origin "$$NEW"; \
	echo "Done — $$NEW pushed. The build pipeline will start automatically."
