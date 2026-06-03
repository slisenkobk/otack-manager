.PHONY: help setup serve dev test unit api e2e e2e-ui reset reset-test reset-uploads logs status install vendor stop clean fresh confirm-destruct package package-check migrate

PORT ?= 8000
SERVER_PID := /tmp/otack-server.pid
DESTRUCT_PW := 309265

help:
	@echo "Otack Tasks — local dev"
	@echo ""
	@echo "  make setup        — copy .env, install npm deps, install playwright browsers"
	@echo "  make serve        — start dev server on http://localhost:$(PORT) (foreground)"
	@echo "  make dev          — start dev server in background (PID at $(SERVER_PID))"
	@echo "  make stop         — stop background dev server"
	@echo "  make status       — show whether dev server is running"
	@echo "  make logs         — tail dev-server log"
	@echo ""
	@echo "  make migrate      — apply pending schema migrations explicitly (CLI/CI)"
	@echo ""
	@echo "  make test         — run all tests (PHP unit + Playwright E2E)"
	@echo "  make unit         — run PHP unit tests only"
	@echo "  make e2e          — run Playwright E2E only"
	@echo "  make e2e-ui       — run Playwright in UI mode"
	@echo ""
	@echo "  make reset        — wipe DEV SQLite + schema markers (fresh dev DB)"
	@echo "  make reset-test   — wipe TEST SQLite + schema + uploads (E2E sandbox)"
	@echo "  make reset-uploads — wipe all DEV uploaded files"
	@echo "  make fresh        — reset DEV DB + uploads + sessions"
	@echo "  make clean        — fresh + remove node_modules + vendor cache"
	@echo ""
	@echo "  make package      — build /tmp/otack-tasks-deploy.tar.gz for shared-hosting upload"

check-env:
	@php bin/check-env.php

setup:
	@if [ ! -f .env ]; then cp .env.example .env && echo "Created .env"; else echo ".env exists"; fi
	@if [ ! -d node_modules ]; then npm install; else echo "node_modules exists"; fi
	@npx playwright install chromium >/dev/null 2>&1 && echo "Playwright chromium ready" || true
	@mkdir -p data data/sessions public/uploads
	@chmod 700 data/sessions
	@php bin/check-env.php

serve:
	php -S localhost:$(PORT) -t public public/index.php

dev:
	@if [ -f $(SERVER_PID) ] && kill -0 `cat $(SERVER_PID)` 2>/dev/null; then \
		echo "Dev server already running (PID `cat $(SERVER_PID)`)"; \
	else \
		nohup php -S localhost:$(PORT) -t public public/index.php > /tmp/otack-server.log 2>&1 & echo $$! > $(SERVER_PID); \
		sleep 1; \
		echo "Dev server started → http://localhost:$(PORT) (PID `cat $(SERVER_PID)`)"; \
	fi

stop:
	@if [ -f $(SERVER_PID) ]; then \
		PID=`cat $(SERVER_PID)`; \
		kill $$PID 2>/dev/null && echo "Stopped dev server (PID $$PID)" || echo "Server already stopped"; \
		rm -f $(SERVER_PID); \
	else \
		echo "No PID file"; \
	fi

status:
	@if [ -f $(SERVER_PID) ] && kill -0 `cat $(SERVER_PID)` 2>/dev/null; then \
		echo "Running (PID `cat $(SERVER_PID)`) → http://localhost:$(PORT)"; \
	else \
		echo "Not running"; \
	fi

logs:
	@touch /tmp/otack-server.log
	@tail -f /tmp/otack-server.log

migrate:
	php bin/migrate.php

test: unit api e2e

unit:
	php tests/run.php

api:
	php tests/api/run.php

# Run the unit suite against MySQL 8.0 in a throwaway docker container.
# Mirrors the CI matrix (.github/workflows/unit-tests.yml). Requires docker
# on PATH. Cleans up the container on success or failure.
unit-mysql:
	@command -v docker >/dev/null || { echo "docker not on PATH"; exit 1; }
	@CID=$$(docker run -d --rm \
		-e MYSQL_ROOT_PASSWORD=rootpw \
		-e MYSQL_DATABASE=otack_test \
		-e MYSQL_USER=otack \
		-e MYSQL_PASSWORD=otack \
		-p 33060:3306 \
		mysql:8.0 --default-authentication-plugin=mysql_native_password); \
	echo "Started MySQL container $$CID — waiting for readiness…"; \
	for i in $$(seq 1 60); do \
		docker exec $$CID mysqladmin ping -uotack -potack --silent >/dev/null 2>&1 && break; \
		sleep 1; \
	done; \
	DB_DSN='mysql:host=127.0.0.1;port=33060;dbname=otack_test;charset=utf8mb4' \
	DB_USER=otack DB_PASSWORD=otack \
	php bin/migrate.php && \
	DB_DSN='mysql:host=127.0.0.1;port=33060;dbname=otack_test;charset=utf8mb4' \
	DB_USER=otack DB_PASSWORD=otack \
	php tests/run.php; \
	STATUS=$$?; \
	docker stop $$CID >/dev/null; \
	exit $$STATUS

e2e:
	npx playwright test

e2e-ui:
	npx playwright test --ui

# Password gate — fires once per `make` invocation; cached because the target
# is phony but make tracks "already built" within a single run.
confirm-destruct:
	@read -s -p "Destructive op — password: " pw; echo; \
		[ "$$pw" = "$(DESTRUCT_PW)" ] || { echo "✗ Wrong password — aborted"; exit 1; }; \
		echo "✓ Authorised"

reset: confirm-destruct
	rm -f data/app.sqlite
	rm -rf data/.schema
	@echo "Dev DB wiped — next request creates fresh schema"

reset-test: confirm-destruct
	rm -f data/app.test.sqlite
	rm -rf data/.schema.test
	rm -rf public/uploads-test
	@echo "Test DB + uploads wiped"

reset-uploads: confirm-destruct
	rm -rf public/uploads/*
	@echo "Dev uploads wiped"

fresh: reset reset-uploads
	rm -rf data/sessions/*
	@echo "Fresh — DB + uploads + sessions all wiped"

clean: fresh
	rm -rf node_modules test-results .playwright
	@echo "Clean — node_modules + Playwright artifacts removed"

# Build a deploy archive — excludes dev-only paths and any local state.
package:
	@rm -f /tmp/otack-tasks-deploy.tar.gz
	@tar --exclude='./.git' \
	     --exclude='./.github' \
	     --exclude='./.gitignore' \
	     --exclude='./node_modules' \
	     --exclude='./tests' \
	     --exclude='./test-results' \
	     --exclude='./.playwright' \
	     --exclude='./.env' \
	     --exclude='./data.backup-*' \
	     --exclude='./data/app.sqlite*' \
	     --exclude='./data/app.test.sqlite*' \
	     --exclude='./data/app.api-test.sqlite*' \
	     --exclude='./data/.schema*' \
	     --exclude='./data/sessions' \
	     --exclude='./data/errors.log' \
	     --exclude='./data/backups' \
	     --exclude='./public/uploads' \
	     --exclude='./public/uploads-test' \
	     --exclude='./.dev-notes' \
	     --exclude='./package.json' \
	     --exclude='./package-lock.json' \
	     --exclude='./playwright.config.ts' \
	     --exclude='./.DS_Store' \
	     --exclude='./Makefile' \
	     -czf /tmp/otack-tasks-deploy.tar.gz .
	@echo "Built /tmp/otack-tasks-deploy.tar.gz ($$(du -h /tmp/otack-tasks-deploy.tar.gz | cut -f1))"

# Sanity-check the tarball produced by `make package`. Lists a sample of paths
# and largest entries, then fails if any forbidden path (dev tooling, test DBs,
# internal docs, secrets) slipped through the exclude list.
package-check: package
	@echo "→ Tarball contents (top 30 paths):"
	@tar tzf /tmp/otack-tasks-deploy.tar.gz | sort | head -30
	@echo ""
	@echo "→ Top 5 largest paths:"
	@tar tzvf /tmp/otack-tasks-deploy.tar.gz | sort -k 3 -nr | head -5
	@echo ""
	@echo "→ Checking for forbidden paths..."
	@if tar tzf /tmp/otack-tasks-deploy.tar.gz | grep -E '(^|/)(\.git(/|$$)|\.dev-notes(/|$$)|test-results(/|$$)|node_modules(/|$$)|package(-lock)?\.json|playwright\.config\.ts|app\.sqlite|data\.backup-|backups(/|$$)|\.env$$)' >/dev/null; then \
		echo "  ✗ FORBIDDEN content found in tarball:"; \
		tar tzf /tmp/otack-tasks-deploy.tar.gz | grep -E '(^|/)(\.git(/|$$)|\.dev-notes(/|$$)|test-results(/|$$)|node_modules(/|$$)|package(-lock)?\.json|playwright\.config\.ts|app\.sqlite|data\.backup-|backups(/|$$)|\.env$$)'; \
		exit 1; \
	fi
	@echo "  ✓ No forbidden paths."
