# Task Time Report Implementation Plan

**Goal:** Implement the approved employee time report with PHP 8.3 and PostgreSQL 15.
**Spec:** ../specs/2026-10-02-task-time-report-design.md
**Architecture:** Symfony 7.4 web application, PostgreSQL DBAL repositories and Messenger background collection. REST 3.0 for tasks, classic REST for time entries and users.

## Global constraints

- PHP >=8.3 <8.4, PostgreSQL 15; explicit OpenServer PHP executable for all PHP commands.
- Keep existing user changes and staging. Work on current master, no worktree or deployment.
- Secrets are provided through application environment/dotenv only and never exposed or copied to tracked files.
- Current role is informational; all employee time entries in the interval count regardless of role.
- No activity exclusion until its completeness is verified. No automatic fallback from REST 3.0.

## Task 1: Runtime and report calculation

Files: composer.json, config/, src/Kernel.php, src/Report/ReportPeriod.php, src/Report/ReportCalculator.php, src/Report/CsvExporter.php, tests/Report/ReportCalculatorTest.php.
Produces: `ReportPeriod(string $from, string $to, string $timezone)`; `ReportCalculator::summarize(array $entries, int $employeeId, ReportPeriod $period): array`; `role(array $task, int $employeeId): string`; CSV from saved rows.

- [x] Install dependencies using the explicit PHP 8.3 binary.
- [x] Write tests for date bounds, seconds (not minutes), duplicates, other users, current roles and CSV formula prevention.
- [x] Run PHP 8.3 `vendor/phpunit/phpunit/phpunit tests/Report`: expected missing production classes.
- [x] Implement period validation and aggregation, then rerun: expected green.

Example independently calculated fixture: two entries of 23 and 3600 seconds, duplicate first entry and a different user's entry produce 3623 seconds and 2 entries.

## Task 2: Bitrix contracts and resumable collection

Files: src/Bitrix/BitrixClient.php, src/Bitrix/BitrixFailure.php, src/Report/TaskReportCollector.php, tests/Bitrix/BitrixClientTest.php.
Consumes: period and calculator. Produces explicit REST 3.0 task pages, classic positional time pages, safe retry metadata.

- [x] Obtain method contracts through MCP; use official version-specific pages when MCP merges versions.
- [x] Write HTTP boundary tests verifying request version/payload and malformed/error responses.
- [x] Run tests before implementing; expected missing client.
- [x] Implement safe HTTP, sequential rate limiting, pagination and normalized rows.
- [x] Verify full pages followed by final/empty pages, access failures and reset metadata.

## Task 3: PostgreSQL jobs, queue and lifecycle

Files: migrations/, src/Report/ReportRepository.php, src/Message/GenerateReport.php, src/MessageHandler/GenerateReportHandler.php, src/Command/ReportMaintenanceCommand.php, tests/Report/ReportRepositoryTest.php.
Consumes: normalized task rows. Produces persisted job status and progress with idempotent row replacement.

- [x] Validate PostgreSQL version, target absence and create permission, then create only the named database.
- [x] Write repository integration tests using a separate PostgreSQL test database, never production tables.
- [x] Run tests before repository implementation; expected missing repository.
- [x] Add migrations and repository; use database advisory lock for one portal collection at a time.
- [x] Process one task per message; store cursor and continuation message in one transaction. Retryable limits use delayed messages; fatal errors remain visible.
- [x] Verify repeat delivery does not duplicate totals and failed collection is never published as complete.

## Task 4: Authorized web report and handoff

Files: src/Controller/, src/Form/, templates/, public/, tests/Controller/, README.md.
Consumes: saved reports, user directory, queue. Produces authenticated form, progress, report and CSV.

- [x] Write functional tests for protected routes, login and invalid/forged forms; run red before controllers.
- [x] Implement Symfony Security, CSRF, validation and escaped Twig views.
- [x] Add employee caching, role column, progress refresh and CSV download of complete reports only.
- [x] Document installation, credentials, worker, cleanup and visibility limits in <=200 README lines.
- [x] Run PHPUnit, PHPStan, PHP lint, Symfony container/Twig/YAML checks and local HTTP smoke tests.
- [x] Review code and security with the project review skill; resolve material findings and rerun affected checks.

## Review focus

Webhook URL leakage, positional legacy payload, REST version selection, total correctness, duplicate delivery, missing roles vs no role, jobs left pending after worker failure, PostgreSQL queue transaction and production/test isolation.

## Execution record

2026-10-02: PostgreSQL 15.5 reachable, configured database absent, create permission available. User authorized implementation including initial database setup. No secrets printed.

2026-10-02: Tasks 1–4 complete. Local implementation verified; live portal acceptance is pending webhook configuration.

Ruling: use a separate database with the `_test` suffix, rather than a schema — Symfony's database suffix provides a clearer boundary; both databases were initially absent and are now migrated.

Ruling: project workflow forbids branches/worktrees and user staging must be preserved — all changes remain uncommitted on master; no deployment or changes to the user's staged index.

Ruling: DBAL was selected in the approved design — STYLE's unconditional entity requirement was clarified to apply only when ORM is selected.

Review: fresh read-only reviewer found three Important issues (dotenv environment precedence, non-JSON retryable HTTP responses, missing identifiers before filtering). Each was reproduced with a failing regression and fixed. Added explicit-offset timestamp validation, generation timestamps, task links, visibility warning and matching CSV columns.

Verification: PHP 8.3 syntax, PHPUnit (including a real PostgreSQL queue send/get/ack and rollback), PHPStan, container/Twig/YAML lint, Composer validation and dependency audit. Local HTTP login and stylesheet returned 200. Both databases have current migrations. No calls to the working Bitrix24 portal; live acceptance remains pending webhook configuration.
