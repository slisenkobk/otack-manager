.PHONY: help setup serve dev test unit e2e e2e-ui reset reset-uploads logs status install vendor stop clean fresh

PORT ?= 8000
SERVER_PID := /tmp/otack-server.pid

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
	@echo "  make test         — run all tests (PHP unit + Playwright E2E)"
	@echo "  make unit         — run PHP unit tests only"
	@echo "  make e2e          — run Playwright E2E only"
	@echo "  make e2e-ui       — run Playwright in UI mode"
	@echo ""
	@echo "  make reset        — wipe SQLite + schema markers (fresh DB on next request)"
	@echo "  make reset-uploads — wipe all uploaded files"
	@echo "  make fresh        — reset DB + uploads + sessions"
	@echo "  make clean        — fresh + remove node_modules + vendor cache"

setup:
	@if [ ! -f .env ]; then cp .env.example .env && echo "Created .env"; else echo ".env exists"; fi
	@if [ ! -d node_modules ]; then npm install; else echo "node_modules exists"; fi
	@npx playwright install chromium >/dev/null 2>&1 && echo "Playwright chromium ready" || true
	@mkdir -p data data/sessions data/.schema public/uploads
	@chmod 700 data/sessions

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

test: unit e2e

unit:
	php tests/run.php

e2e:
	npx playwright test --config tests/e2e/playwright.config.ts

e2e-ui:
	npx playwright test --config tests/e2e/playwright.config.ts --ui

reset:
	rm -f data/app.sqlite
	rm -rf data/.schema
	@echo "DB wiped — next request creates fresh schema"

reset-uploads:
	rm -rf public/uploads/*
	@echo "Uploads wiped"

fresh: reset reset-uploads
	rm -rf data/sessions/*
	@echo "Fresh — DB + uploads + sessions all wiped"

clean: fresh
	rm -rf node_modules test-results .playwright
	@echo "Clean — node_modules + Playwright artifacts removed"
