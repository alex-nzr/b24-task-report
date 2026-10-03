# Report selection implementation plan

> Execute the approved design with TDD and task-scoped review; use subagent-driven-development for the backend task.

**Goal:** Reduce REST enumeration and deliver immediate bounded collection and readable/shareable reports.
**Architecture:** Classic server-side selection feeds the existing incremental processor. One shared runner serves Command and validated HTTP submission; Vue renders safe snapshots.
**Tech Stack:** PHP 8.3, PostgreSQL 15, Symfony 7.4, Vue, official B24 UI Kit.
**Spec:** ../specs/2026-10-02-report-selection-design.md

## Global constraints

- PHP 8.3 and PostgreSQL 15; current workspace/master, no commits or new worktrees.
- Shared advisory lock; 45-second default soft budget; no persistent worker or shell launch.
- Server filter current responsible OR accomplice and activity lower bound only; classic REST exception documented.
- Retain source seconds, partial rows and explicit errors; no false complete reports.
- Never print credentials. README <= 200 lines.

## Task 1: Backend selection, runner, form and migration

Files: src/Bitrix/BitrixClient.php, src/Report/{ReportProcessor,TaskReportCollector,ReportRepository,ReportCalculator,ReportRunner}.php, src/Command/ReportCollectCommand.php, src/Controller/ReportController.php, src/Form/ReportRequestType.php, config/bootstrap.php, .env.example, migrations/Version20261002000400.php; matching tests/Bitrix, tests/Report, tests/Controller, tests/Configuration.
Interfaces: collectNow form boolean; run.total_tasks nullable integer in safe snapshots; run.employee_id safe integer for compact links; ReportCalculator::formatSeconds returns accumulated HH:mm; backend runner shared by command/controller, targets submitted report and skips busy global lock.

- [x] Add failing HTTP-boundary filter tests, total/progress tests, busy/targeted/invalid-form immediate tests, missing-config fallback regression and migration checks.
- [x] Run focused PHPUnit with explicit PHP 8.3 and observe expected failures.
- [x] Implement exact filters and validated normalized list pages; use list role data without participant lookups for new reports. Retain legacy safeguards, mark old active selection invalidated rather than mix rules.
- [x] Add shared runner and validated collectNow handler; command default 45 and existing manual workflow preserved.
- [x] Add migration, remove application profile fallback and unused Messenger env configuration.
- [x] Run relevant tests, PHPStan and container checks; record RED/GREEN evidence.

## Task 2: Presentation and clipboard

Files: assets/pages/{IndexPage,ReportPage}.vue, templates/reports/{index,show}.html.twig, assets/report-format.js, tests/ui/report.spec.js, package.json if necessary.
- [x] Add failing browser/format tests for date timezone, >24h durations, progress totals, checkbox semantics, compact modal and clipboard/nonsecure fallback.
- [x] Render checkbox/explanation in Vue and server fallback; server validates flag.
- [x] Render HH:mm/date strings and initial total denominator; compact URLs use employee_id and validated portal base, explicit incomplete warning.
- [x] Run production build and desktop/mobile Playwright tests.

## Task 3: Documentation, Git and final review

- [x] Update AGENTS scope/REST exception, README and lifecycle actual flow/schema/immediate wait constraints; remove obsolete profile references from documentation.
- [x] Ignore and untrack only .ai/prompts/init.md while retaining file.
- [x] Apply local migration only after checking unused table, verify PostgreSQL schema and read-only configured test-portal filter behavior without personal data output.
- [x] Run full PHPUnit, PHPStan, relevant lints, build and UI tests; independent review and focused corrections.
