# Testing

This project ships three test suites and a hand-rolled runner. Pick the suite
that matches the layer you're changing — they don't overlap.

## Suites

| Suite | Path | Runner | Driver | What it covers |
|-------|------|--------|--------|----------------|
| Unit  | [`tests/unit/`](../tests/unit/) | [`tests/run.php`](../tests/run.php) | SQLite (file) + MySQL (CI matrix) | Repositories, services, DSL/SQL compilation, convention guards |
| API   | [`tests/api/`](../tests/api/)   | [`tests/api/run.php`](../tests/api/run.php) | SQLite | `/api/v1/*` kernel — auth, rate-limit, routing, JSON shapes |
| E2E   | [`tests/e2e/`](../tests/e2e/)   | Playwright                | SQLite (via cli-server) | Real-browser golden paths, a11y, mobile breakpoints |

## Running locally

```bash
make test           # unit (SQLite)
make test-mysql     # unit (MySQL) — needs MYSQL_DSN/USER/PASSWORD in .env
make api            # API suite
make e2e            # Playwright (auto-resets test SQLite + uploads-test/)
make check          # unit + api in one go
```

## Conventions

- Each unit file lives next to one production file, named `test_<thing>.php`.
- Tests use `it('description', function () { ... })` + `assert_eq`, `assert_true`,
  `assert_false`. No fluent matchers — keep the runner one screen of code.
- DDL tests build a `Blueprint`, compile via the driver, assert on the SQL
  string. They never open a real DB unless the test name says "end-to-end".
- Convention tests (`test_*_conventions.php`) lock invariants like "all
  controllers use constructor injection" or "every i18n key is referenced".
  Add one when a refactor depends on an invariant you don't want to drift.

## When to consider migrating off the hand-rolled runner

We deliberately use a tiny PHP test runner at [`tests/run.php`](../tests/run.php)
instead of Pest/PHPUnit. This is fine while:

- Total wall-clock under 30 s
- Under ~400 unit tests
- No cross-test DB pollution requiring per-test resets
- No need for shared fixtures / dataset providers / annotations beyond
  `it()` + the three assert helpers

When any one of those tips, evaluate migrating. The runner contract is
minimal (`it()`, `assert_eq()`, `assert_true()`, `apply_migration()`) so the
swap is straightforward — write a Pest preset that aliases those four
symbols and migrate suites incrementally.

The CI matrix (unit-sqlite, unit-mysql, api, e2e) is the source of truth
for "green" on a PR — keep it green before you tag a release.
