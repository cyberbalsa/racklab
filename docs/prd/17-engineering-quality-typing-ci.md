# Engineering Quality, Typing, And CI

> **Note:** Implementation detail for the engineering stack choices in this section (formatter, static analyser, test runner, security scanners, custom Larastan rules, CI matrix) lives in `docs/superpowers/specs/2026-05-26-laravel-redesign.md` §8. This document captures the discipline contract — TDD, coverage gates, no-overrides rule, snapshot CI gates; the spec is the source of truth for the tools that enforce them.

RackLab should be built as a production-grade Laravel project with strict quality gates.

## Baseline Stack

- PHP 8.3+.
- Composer for package management and lockfile control.
- Laravel 13.x.
- Laravel Octane (FrankenPHP driver — ASGI-equivalent persistent worker model; required for the SSH console plugin's WebSocket consumers and any future bidirectional console plugins; real-time push is handled by Reverb WebSocket + `broadcast_event_log` replay).
- Laravel Reverb (WebSocket server for real-time; Eloquent-backed event log with `Last-Event-ID` replay semantics).
- PostgreSQL.
- Redis (queue, cache, rate-limiting).
- Laravel REST API layer + Scribe for OpenAPI schema generation.
- Frontend: Blade templates + Livewire 4 components for CRUD surfaces + vanilla JS islands (via Vite) for interactive terminals/viewers (xterm.js, noVNC, TipTap). TypeScript strict mandatory for the vanilla JS islands. See PRD §15 for the full slate.
- Plugin system via Composer manifest (`extra.racklab` convention) + `PluginRegistry` + typed hookspec event bus.
- `phpseclib` / SSH client (SSH console plugin).
- Pest 4 (test runner at all layers).
- Laravel model factories (Eloquent).
- Pint (formatter).
- Larastan at PHPStan max level (static analysis, custom rules).
- Rector (automated refactors and upgrade path).

## Strict Typing

RackLab uses PHP 8.3+ strict types throughout:

- Every file declares `declare(strict_types=1)`.
- Larastan runs at PHPStan **max level** with custom rules (see Custom Larastan Rules section below).
- PHP attribute `#[Untenanted]` is the documented opt-out for models that provably have no tenant context; `UntenantedRule` enforces the allowlist in CI.

Explicit typing is required for:

- Plugin hookspec event classes.
- Provider interfaces.
- Event payloads.
- Queue message DTOs.
- API serializers and schemas.
- Quota policies.
- Scheduler inputs and outputs.
- Script definitions.
- Token claims.
- Audit event payloads.

Runtime validation is required where data crosses trust or process boundaries:

- API input.
- Plugin config.
- Queue payloads.
- Provider responses.
- Script definitions.
- Token claims.

## Linting And Formatting

The tool chain is Pint (format) + Larastan max level (static analysis) + Rector (automated refactors). The discipline is **maximum strictness with no overrides** — when the linter says no, the code changes, not the linter config.

### Strong default configuration

- **Pint** is configured with the full Laravel preset plus any project-specific rule overrides documented in `pint.json`. Per-file ignores are not permitted; formatting drift fails CI.
- **Larastan** runs at PHPStan max level project-wide. Custom rules (see below) enforce tenancy, no-overrides, hookspec typing, scope bypass, and bare event dispatch discipline. `treatPhpDocTypesAsCertain = false`; all generics must be properly typed.
- **Rector** is configured with the `LaravelSetList` and `PHPUnitSetList`. `--dry-run` runs in CI; failures indicate automated-refactor debt that must be resolved.
- **Frontend linting**: there is no React/JSX in the tree, so JSX-specific linters (`eslint-plugin-jsx-a11y`, `eslint-plugin-react`, `eslint-plugin-react-hooks`) do not apply. ESLint with `eslint:recommended` runs on the vanilla JS island TypeScript sources under `resources/js/islands/`. Prettier formats those sources. Stylelint covers any hand-authored CSS. Pint formats Blade and PHP. a11y is enforced through axe-core inside every Dusk browser test (see CI section below). Same no-overrides discipline as PHP — no `// eslint-disable` inline.
- **Markdown linting** uses `markdownlint-cli2` (see `.markdownlint.jsonc`). The disabled-by-default rules are documented inline with the reason; new disabled rules require a documented justification.

### No overrides

Inline lint-override comments are **forbidden in production code** (`app/`, `packages/racklab/*/src/`). CI enforces this via `NoLintOverridesRule` (a custom Larastan rule) and a grep gate that fails the build on any of:

- `@phpstan-ignore` (PHPStan) — fix the type or extend the stub.
- `// @phpstan-ignore-line` (PHPStan) — fix the type or extend the stub.
- `// @phpstan-ignore-next-line` (PHPStan) — fix the type or extend the stub.
- `@psalm-suppress` (Psalm, if ever introduced) — fix the type or extend the stub.
- `// @phpcs:ignore` (PHP_CodeSniffer, if ever used) — fix the code instead.
- `// @phpcs:disable` (PHP_CodeSniffer) — fix the code instead.
- `// eslint-disable` / `/* eslint-disable */` / `eslint-disable-next-line` (ESLint).
- `// stylelint-disable` (Stylelint).
- `// @ts-ignore` / `// @ts-expect-error` (TypeScript).
- `<!-- markdownlint-disable ... -->` (markdownlint).
- `// noqa` (unsupported lint override; should not exist in the codebase, rejected on sight).

The grep gate runs in the pre-commit hook (`lefthook` or `captainhook`) and in CI on every PR. There are exactly **two** narrow exceptions, both audited:

1. **Test code in `tests/` directories** may use `@phpstan-ignore` *exactly* when intentionally testing a runtime-only attribute that Larastan can't see (e.g., Eloquent `RelatedManager` attributes under specific mocking patterns). Each occurrence requires a one-line comment naming the workaround and a link to a tracking issue. Reviewed at every cycle.
2. **Auto-generated code** (`database/migrations/` and other Laravel auto-generated artifacts) is excluded by path glob in `larastan.php`, not by inline comments. The path-glob list is short and version-controlled.

If Larastan is genuinely wrong in a specific case (rare), the team:

1. Opens an issue documenting the case with a minimal reproducer.
2. Discusses whether to update or extend the Larastan/PHPStan rule (preferred), introduce a typed wrapper that satisfies the analyser (preferred), update the Larastan version (if it's a bug fixed upstream), or — only as last resort — add a path-glob ignore with a documented expiration date.
3. Never adds an inline `@phpstan-ignore` or `@psalm-suppress`.

This discipline is load-bearing for AI-assisted development. AI is tempted to silence the linter rather than fix the underlying issue; forbidding the silencing comments forces the actual fix.

### CI rejects

CI rejects on:

- Formatting drift (`pint --test`).
- Larastan violations at max level.
- Rector dry-run failures (automated-refactor debt).
- Forbidden lint-override comments anywhere outside the two narrow exceptions above.
- Test failures at any layer.
- Coverage gates per layer (TDD discipline section below).
- Dependency audit failures (`composer audit` + `npm audit`).
- Security scan failures (`roave/security-advisories` abort on install; `enlightn/security-checker`; Semgrep with Laravel/PHP rule packs; CodeQL on `main`).
- Permission-snapshot drift without a paired test update.
- Audit-event-emission test failures (missing emission for a documented event is a P0).
- OpenAPI schema drift without a committed-schema update (Scribe-generated artifact must be committed and diff-clean).

## Test-Driven Development Discipline

RackLab is built test-first. This is non-negotiable, and it is particularly load-bearing because most of the implementation will be AI-assisted: tests are the durable contract between AI-generated code and human-defined behavior. AI-generated code can be regenerated, refactored, or swapped — the tests stay. Test-first prevents the failure mode of "AI confidently writes broken code; reviewer doesn't catch it."

Discipline:

- **Write the failing test first.** Every new behavior is preceded by a failing Pest test that captures the requirement. A change that adds a feature without a test that previously failed is rejected in review.
- **Fix the test, not the implementation, when the test is wrong.** If a test passes when it shouldn't, fix the test. If a test fails when the new behavior is intentional, update the test deliberately and document the change in the commit.
- **Belt and suspenders.** The same logic is exercised at multiple layers (unit + contract + integration + E2E where applicable). A bug that slips a unit test gets caught by integration; a bug that slips integration gets caught by E2E. Overlap is the point.
- **Tests are not optional documentation.** They are the executable specification. The PRD describes what; the tests prove what.
- **Mutation testing on critical modules.** `pest --mutate` runs **nightly, not per-PR** on high-stakes surfaces: `AccessResolver`, `CrossTenantFetch`, quota math, the universal `Job` state machine, the Proxmox task-poller state machine, the audit hash-chain head, plugin lifecycle, and the Track A JWT issuer/verifier. Mutation-score thresholds are reported in the CI summary; regressions block release tags.
- **Coverage gates per layer.** 90% on tiny / unit, 80% on contract, 70% on integration, and explicit named E2E flows for every user-facing journey. Coverage is necessary but not sufficient — mutation testing and named E2E flows backstop it.

## Testing

Four named test layers, all required:

### Tiny (unit)

Pure-PHP `app/Domain/*`, no Laravel boot, no I/O. Pest 4 with no framework magic where avoidable, no database. Each test runs in milliseconds; the suite is thousands of tests.

- RBAC predicates and permission-string parsing.
- Quota math and reservation logic.
- Hookspec dispatch logic.
- Audit hash-chain head computation.
- Plugin manifest validation.
- State-machine transitions on `Job` and its subtypes.
- Domain-model invariants (capability flag arithmetic, plural-form resolution, UPID parsing, asciinema redaction pattern matching).

### Contract

Module-boundary tests verifying interface contracts. Use in-memory Laravel fakes (`Storage::fake()`, `Queue::fake()`, `Bus::fake()`, `Event::fake()`, `Http::fake()`) for collaborators; the unit under test is real.

- Plugin hookspec events: each hookspec event is tested with at least one fake listener that exercises every parameter shape and every documented failure mode.
- `WorkerRuntime` Protocol: both Quadlet and Nomad runtimes pass the same Protocol-level test suite. Plugin code is tested against the narrow `PluginWorkerRuntime` interface.
- `ProxmoxClient` facade: tests run against the typed PHP facade (`App\Providers\Proxmox\Client`) and assert on the public surface; the codegen-derived endpoint mapping is validated against a recorded Proxmox API schema (generator snapshot test); the Guzzle transport boundary is tested separately.
- Provider plugin interface: every contributed provider plugin runs the same contract suite.
- Console backend interface: same.
- API controller round-trip (validation → Eloquent → response) at the API boundary.
- Reverb channel auth callbacks and consumer round-trips.
- Tenant context propagation: middleware chain carries correct `tenant_id` through the request lifecycle.

**Required negative-path contract tests** (CI fails if any is missing):

- `CrossTenantAllowedTest` — `AccessResolver` returns `allowed` with correct provenance for `multi_tenant` binding + matching `sharing_scope`.
- `CrossTenantDeniedTest` — `AccessResolver` returns `denied` with reason `insufficient_scope` when binding scope ⊉ resource tenant.
- `CrossTenantIssuanceDeniedTest` — Granter with `tenant_local` scope cannot issue `multi_tenant` binding; audit issuance-variant fires with `result=denied`.
- `ReplayGapSentinelTest` — Replay endpoint returns a `gap` sentinel when `since` ULID is older than the sweep window (24h).
- `PluginListenerFailureIsolationTest` — A contributor-style listener that throws does not prevent other contributors from running; failure is surfaced in the aggregated result.

### Integration

Multi-module flows with real infrastructure. Postgres 16 via Testcontainers (PHP binding); Redis 7 via Testcontainers; Podman socket for container tests; fake provider in process.

- Deployment lifecycle: catalog selection → quota reservation → queue dispatch → Horizon pickup → fake provider clone → reconciliation → status reaches `running`. Includes a deliberate worker crash mid-job and verifies the reconciler resumes without re-submitting.
- Plugin lifecycle: install → migrate → enable → run hook → disable → uninstall. Migration rollback verified end-to-end.
- Reverb replay: client disconnects mid-stream with `Last-Event-ID = N`; reconnect resumes from `N+1`; events older than the retention window produce the sentinel.
- TLS admin GUI: switch issuance profile triggers the Caddyfile rewrite + FrankenPHP/Caddy reload-or-restart; uploaded cert hot-reloads; force-renew rate-limited to 1/hour.
- SSH plugin: `ConsoleAccessGrant` validated, SSH client connects with pinned host keys, redaction pipeline replaces patterns, abort-on-redaction-failure terminates recording but keeps session live.
- Universal `Job` ledger: every job kind (provider/script/console/notify/reconciler/docs) writes to `Job` with the right subtype and is observable by reconciliation queries.

**Required negative-path integration tests** (CI fails if any is missing):

- `ContainerEgressDeniedTest` — A `--network=none` container cannot reach external hosts; test exec's `curl` in the container and asserts the call fails.
- `ProxmoxNoResubmitIdempotencyTest` — Two consecutive job dispatches with identical idempotency key + an existing UPID never call `POST` on Proxmox a second time.
- `TrackAJwtKeyRotationTest` — After JWKS key rotation, old JWT (still in TTL window) still verifies; new tokens use new key id; old `kid` is removed after grace period.
- `TrackAJwtRevocationTest` — `jti` blacklist entry blocks verification within next-cache-flush window.

### End-to-end (E2E)

Full system, browser-driven. Real Postgres, real Redis, real Horizon workers (Quadlets in CI), real FrankenPHP/Caddy, a fake Proxmox (a small PHP HTTP server speaking the Proxmox API for the endpoints RackLab uses), a real RackLab core. Browser automation via **Laravel Dusk v8.6** with **axe-core** integration (every Dusk test asserts no axe-core violations on the page-load snapshot).

Named user journeys covered:

- Student logs in, browses catalog, deploys a one-VM Stack into the Project's Default Stack, opens noVNC console, opens SSH console, runs a script, restores from snapshot, releases the deployment, sees quota return to zero.
- Instructor publishes a catalog item, deploys to a roster, manages a failing student deployment.
- Admin configures a custom ACME issuer, watches first cert issue, switches LE staging → production, force-renews.
- Admin installs a plugin via Composer, migrates it, enables it, sees a new permission appear in RBAC, disables it, rolls it back, uninstalls.
- Admin uploads a custom theme, switches the deployment to it, sees the login banner change.
- Guest opens a share-link, lands on a deployment detail with redacted references for things they can't see.
- Accessibility: axe-core runs against each critical-flow page during E2E and fails the build on any new violations.

**Required negative-path browser test** (CI fails if missing):

- `ConsoleProxyAuthDeniedTest` — A console-script container with an expired Track A JWT receives 401 from `console-proxy.sock`; xterm shows the error.

### Livewire / JS-island layers

Required for any Livewire 4 component or vanilla JS island:

- **Pest 4 browser layer via Laravel Dusk** with axe-core integration — Dusk drives Livewire components through the full Laravel stack; axe-core assertions run on every page-load snapshot. Component-level Livewire tests use Pest's Livewire test helpers (`Livewire::test()`) and run without a browser. Vanilla JS islands (xterm, noVNC, Chart, TipTap, FilePond) are covered by Dusk tests of the pages that mount them.
- **No Storybook.** Livewire components are server-rendered and live inside their host pages; the Pest + Dusk browser layer is the integration-test surface. Filament 5 admin components have their own Filament-native test helpers.
- **PHP FormRequest validation + typed DTOs** for every API request/response shape the island consumes; failures are explicit, not silent.
- **TypeScript strict** must pass for the vanilla JS island TypeScript sources under `resources/js/islands/` (CI gates on `tsc --noEmit`).

Coverage gates for the front-end surface: 80% on Pest 4 component/feature tests (Livewire + Filament) plus Dusk browser tests; named E2E flows for every user journey.

### Cross-layer rules

- Worker tests at every layer use fakes for queue and provider (tiny/contract) and real for higher layers; fake provider implementations live in `tests/Fakes/`.
- The Proxmox client boundary is mocked at the contract layer; the real facade is exercised in nightly integration runs against a Proxmox VE test cluster (operator-provided; CI skip if unavailable).
- Container-sandbox script tests use real Podman in CI Linux runners (Linux-only; macOS/Windows dev environments use a smaller fake-sandbox runner).
- Permission regression tests are a contract-layer snapshot suite that captures the full set of permissions per role and refuses to merge if the snapshot changes without an updated test.
- Audit event tests verify every documented audit event is emitted from the code path that should emit it. Missing audit emission is a P0 bug in this project.

## Custom Larastan Rules

Six custom rules live in `tests/Larastan/Rules/` and run as part of the `larastan` CI step:

1. **`UntenantedRule`** — fails if `class extends Model` AND no `tenant_id` column declared in migrations AND no `#[Untenanted]` PHP attribute. Allowlist enforced in CI.
2. **`NoLintOverridesRule`** — fails if `app/` or `packages/racklab/*/src/` contains a `@phpstan-ignore*` or `@psalm-suppress` annotation.
3. **`HookspecEventTypedRule`** — fails if a class in `app/Events/Hookspecs/**/*Event.php` is not `readonly` or doesn't have typed properties.
4. **`NoBareScopeBypassRule`** — fails on `withoutGlobalScopes()` or `withoutGlobalScope(TenantScope::class)` outside `app/Domain/Tenancy/CrossTenantFetch.php` (the only allowed cross-tenant fetch entry point).
5. **`NoSpatieBypassRule`** — fails on direct calls to `$user->hasRole(…)` / `$user->can(…)` outside `App\Domain\Tenancy\AccessResolver`. All authorisation decisions must go through `AccessResolver`.
6. **`NoBareEventDispatchOnHookspecsRule`** — fails on `Event::dispatch(SomeHookspec\Event::class)` or `Event::until(SomeHookspec\Event::class)` outside `app/Plugins/HookDispatcher.php`. Forces dispatch semantics to go through the typed dispatcher.

## Snapshot CI Gates

- **`tests/Snapshots/RolePermissions.test.php`** — asserts each role's permission set matches `tests/Snapshots/roles.json`. A PR that mutates permissions must update the snapshot.
- **`tests/Snapshots/AuditEvents.test.php`** — asserts every documented audit event in `docs/prd/14-audit-logging-observability.md` has a code path emitting it.

## CI

CI runs on every push and pull request against a PHP 8.3 + 8.4 matrix with Laravel 13.x, Postgres 16 + Redis 7 + Podman 5 (Testcontainers-managed). Each layer is a separate job so failures are diagnosable per-layer; jobs run in parallel where independent.

Required PR-blocking jobs:

1. `pint --test` — formatting drift check.
2. `larastan --memory-limit=2G --no-progress` — static analysis at max level, custom rules included.
3. `rector --dry-run` — automated-refactor debt check.
4. `composer install --no-ansi` (`composer.lock` integrity) + `npm ci` (lockfile integrity).
5. `pest --parallel --coverage --min=90` — tiny layer; must complete in under 60s for the whole suite; no I/O, no database.
6. `pest --testsuite=contract` — contract layer (≥80% coverage).
7. `pest --testsuite=integration` — integration layer with Testcontainers-provided Postgres + Redis + Podman (≥70% coverage).
8. `pest --testsuite=browser` — Dusk browser layer; named user journeys; axe-core a11y assertions on every page-load.
9. Permission-snapshot gate (`RolePermissions.test.php`) — refuses to merge if the role-permission snapshot changes without an explicit test update.
10. Audit-emission gate (`AuditEvents.test.php`) — refuses to merge if a documented audit event has no code path emitting it.
11. `composer audit` + `npm audit` — dependency CVE scan.
12. Security scanning: `roave/security-advisories` (composer dev dep that aborts install on known-CVE deps), `enlightn/security-checker` for Laravel-specific patterns, `phpcs-security-audit` for taint analysis on `app/Http/`, Semgrep with Laravel + PHP rule packs for OWASP Top 10; CodeQL on push to `main`.
13. OpenAPI schema-drift gate: `php artisan scribe:generate --no-extraction` then `git diff --exit-code docs/api/openapi.yaml` — PRs that change the route surface must update the committed OpenAPI artifact.
14. a11y gate — axe-core runs inside every Dusk browser test; new violations fail the build.
15. i18n catalog drift — `php artisan racklab:lang:check` (custom artisan command, not a Laravel core command) fails if any Blade/Livewire template uses `__('…')` with a string not present in `resources/lang/en/*.php` or vice-versa.
16. TypeScript strict (`tsc --noEmit`) on the vanilla JS island TypeScript sources under `resources/js/islands/`.
17. ESLint (with `eslint:recommended`; no JSX-specific plugins) on the same vanilla JS island sources.
18. *(reserved)* — the prior Storybook a11y-addon build is no longer relevant; a11y coverage on Livewire/Filament-rendered pages happens through Dusk + axe-core (job 14).
19. Plugin contract smoke — the `racklab/plugin-hello` reference plugin must install/migrate/enable/disable cleanly against the PR's RackLab API.

Required non-blocking jobs:

- *(none currently — all previously informational jobs have been promoted to PR-blocking)*.

Nightly / cron jobs:

- `pest --mutate` on high-stakes surfaces: `AccessResolver`, `CrossTenantFetch`, quota math, Job state machine, Proxmox task-poller state machine, audit hash-chain head, plugin lifecycle, Track A JWT issuer/verifier. Mutation score posted to PR comments; regressions block release tags.
- Integration tests against a real Proxmox VE test cluster (operator-provided; skipped if absent).
- Long-running soak tests for Horizon worker stability and the Reverb replay window.
- Plugin lifecycle full round-trip (install → migrate → enable → exercise → disable → rollback → uninstall) for every official plugin.

CI is the gate. A PR that fails any blocking job does not merge.
