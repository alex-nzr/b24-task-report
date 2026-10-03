# Command report implementation plan

> Execute inline using executing-plans and TDD. User approved the spec in chat.

**Goal:** Replace persistent Messenger collection with a bounded Command, visible partial results/errors and comprehensive documentation.
**Architecture:** Database jobs and a resumable collector; CLI schedules ready steps under a PostgreSQL advisory lock. Symfony HTML/progress JSON/CSV read the same saved snapshot.
**Tech stack:** PHP 8.3, PostgreSQL 15, Symfony 7.4, Vue/B24 UI Kit.
**Spec:** docs/superpowers/specs/2026-10-02-command-report-design.md

## Global constraints

- Default time-limit=45; manual local invocation, cron setup belongs to the user.
- Preserve report data and user index; no commits, worktree or deployment.
- No environment values, HTTP bodies, webhook URLs or stack traces in outputs.
- REST 3.0 priority, sequential calls with shared 1/sec limiter.
- README <=200 lines; describe implemented behavior, not plans.

## Task 1: Database collector and CLI

Files: migrations/Version20261002000300.php, src/Report/{ReportRepository,ReportProcessor,TaskReportCollector,TaskCollectionFailure}.php, src/Command/ReportCollectCommand.php, src/Bitrix/BitrixClient.php, config/packages/framework.yaml, composer.json/lock; remove obsolete Message/handler/subscriber.

Interface: ReportProcessor::step(string $id): void; repository nextReady(): ?string and errors(string $id): array. TaskCollectionFailure carries stage, BitrixFailure, optional time row.

- [x] Write integration tests proving resume, duplicate-safe rows, time retained after role error, unknown time not zero, bounded retries, list failure, lock and default command behavior. Run RED.
  ```php
  $processor->step($id);
  self::assertSame('Не удалось определить', $reports->rows($id)[0]['role']);
  self::assertSame(23, $reports->rows($id)[0]['seconds']);
  self::assertSame('participants', $reports->errors($id)[0]['stage']);
  ```
- [x] Add additive errors/last_processed_at migration, run on test DB; implement processor/command. Upsert errors by run/task/stage; update cursor and row in a transaction. Command validates positive integer, locks globally, loops ready steps until budget or none, releases lock finally.
- [x] Replace Messenger tests/config with job transaction tests; remove obsolete production classes/dependencies without touching unrelated files or report data. Stop legacy worker gracefully before switching main schema.
- [x] Verify documented REST participant select against official contract and targeted request, add regression first if a supported workaround is found. Otherwise retain explicit role-error reporting, never fallback to classic silently.
- [x] Run relevant PHPUnit and PHPStan GREEN.

## Task 2: Progress and truthful exports

Files: src/Controller/ReportController.php, src/Report/CsvExporter.php, templates/reports/show.html.twig, assets/pages/ReportPage.vue, assets/components/RunStatus.vue, tests/Controller/ReportControllerTest.php, tests/ui/report.spec.js.

Interface: GET /reports/{id}/progress => {run,rows,errors,total,complete}; terminal statuses complete, complete_with_errors, failed. No secrets/cursors in presentation payload. CSV exporter consumes rows and snapshot metadata/errors.

- [x] Add failing functional/browser tests for partial rows/total, error stages, no refresh navigation, protected progress JSON and CSV warning. Run RED.
  ```php
  $browser->request('GET', $location.'/progress');
  self::assertResponseIsSuccessful();
  self::assertSame(23, $browser->getResponse()->toArray()['rows'][0]['seconds']);
  ```
- [x] Implement safe presentation snapshot shared by HTML/JSON/CSV; remove meta refresh; retain escaping, CSRF and normal routes.
- [x] Vue polls every five seconds without overlap, stops on terminal/unmount; retains data on request failure. Always render rows/errors, mark incomplete sums and CSV.
- [x] Run build, PHP tests and Playwright desktop/mobile GREEN.

## Task 3: Documentation and final verification

Files: README.md, docs/report-lifecycle.md, docs/bitrix-ui.md, docs/verification.md, AGENTS.md when operational rules change.

- [x] Trace actual source and document form, routes, complete DB field dictionary, collection scheduling, retry/error semantics, CSV lifecycle, purge, manual command and production cron examples. Clarify soft budget and inaccessible tasks.
- [x] Run full PHPUnit, PHPStan, PHP syntax, Symfony container/Twig/YAML lint, build, browser checks and dependency audits. Confirm README line limit and links.
- [x] Fresh independent read-only review. Address concrete findings, preserve user index and no commits/deployment.

## Execution ledger

2026-10-02: approved spec. Tightly coupled collection/storage/presentation interfaces are implemented inline; independent final review delegated. Project rules override worktree/commit/model-selection defaults. Legacy Messenger table will be retained unused to avoid unnecessary destructive migration; documented as historical, not the active queue.

Completed: main/test migration003, graceful legacy worker stop, Messenger dependencies/classes removed; historical queue retained. PHPUnit38/152, Playwright14, PHPStanlevel5, Symfony lint, syntax33, build and dependency audits green; HTTP login/assets200/authprogressredirect. README163. Independent review2Important findings reproduced RED, fixed, scoped reviewclean. Known saved time is reused for role retries; ordinary retry budget excludes method-limit pauses. Role executor inferred from authoritative list responsibleId; external REST participant500 remains explicitly reported for other roles. No scheduler setup, live report rerun, commit or deploy.
