# Engineering Quality, Typing, And CI

RackLab should be built as a production-grade Django project with strict quality gates.

## Baseline Stack

- Python 3.12+.
- `uv` for package management and lockfile control.
- Django 5.2 LTS.
- Django Channels (ASGI WebSocket consumers — required by the SSH console plugin and any future bidirectional console plugins; SSE remains a Django-view concern over ASGI).
- Pydantic v2 (Proxmox client facade models, plugin config validation, queue-message validation, OpenAPI schema source-of-truth where used alongside DRF).
- PostgreSQL.
- NATS JetStream.
- Django REST Framework.
- `drf-spectacular`.
- Frontend: Django templates + React islands via `django-vite` 3.1 + Mantine (latest stable). Vite 8 + `@vitejs/plugin-react-swc`. TypeScript 5.5+ strict mandatory for the React tree. LinguiJS v6 for catalogs. See PRD §15 for the full slate.
- `pluggy` and Python entry points.
- `proxmoxer`.
- `asyncssh` (SSH console plugin).
- `pytest`.
- `pytest-django`.
- Factory fixtures.
- Ruff.
- mypy.
- `django-stubs`.
- `django-stubs-ext`.
- `djangorestframework-stubs`.
- Pyright or basedpyright.
- Pylance recommended for VS Code developers.

## Strict Typing

RackLab uses a dual type-checking setup:

- mypy with `django-stubs`, `django-stubs-ext`, and `djangorestframework-stubs` for Django-aware CI checking.
- Pyright/Pylance strict mode for fast editor feedback.
- basedpyright may be used if a Python-package-managed stricter Pyright fork is preferred for CI.

Explicit typing is required for:

- Plugin hook specs.
- Provider interfaces.
- Event payloads.
- NATS messages.
- API serializers and schemas.
- Quota policies.
- Scheduler inputs and outputs.
- Script definitions.
- Token claims.
- Audit event payloads.

Runtime validation is required where data crosses trust or process boundaries:

- API input.
- Plugin config.
- NATS payloads.
- Provider responses.
- Script definitions.
- Token claims.

## Linting And Formatting

Ruff is the primary linter and formatter. The discipline is **maximum strictness with no overrides** — when the linter says no, the code changes, not the linter config.

### Strong default configuration

- **Ruff** is configured with the full rule set RackLab adopts (`pycodestyle`, `pyflakes`, `mccabe`, `pyupgrade`, `flake8-bugbear`, `flake8-comprehensions`, `flake8-simplify`, `flake8-bandit`, `flake8-async`, `flake8-django`, `pylint` selected rules, `ruff` native rules) at their strictest sensible settings. Per-file ignores in `pyproject.toml` are reviewed quarterly and trimmed; "we always ignore this rule" is reconsidered every cycle.
- **mypy** runs in strict mode (`strict = true`) project-wide with `django-stubs` + `django-stubs-ext` + `djangorestframework-stubs`. `warn_unused_ignores = true`, `disallow_any_explicit = true`, `disallow_any_decorated = true` where practical.
- **Pyright / basedpyright** runs in strict mode for editor feedback.
- **Frontend linting**: ESLint with `eslint:recommended` + `eslint-plugin-jsx-a11y` (a11y rules — hard requirement, no overrides) + `eslint-plugin-react` + `eslint-plugin-react-hooks`. Prettier formats. Stylelint for any hand-authored CSS. Same no-overrides discipline as Python — no `// eslint-disable` inline.
- **Markdown linting** uses `markdownlint-cli2` (see `.markdownlint.jsonc`). The disabled-by-default rules are documented inline with the reason; new disabled rules require a documented justification.

### No overrides

Inline lint-override comments are **forbidden in production code**. CI enforces this via a grep gate that fails the build on any of:

- `# noqa` (ruff/flake8) — fix the code instead.
- `# type: ignore` (mypy) — fix the type or extend a stub package.
- `# pylint: disable` (pylint, if ever used) — fix the issue.
- `// eslint-disable` / `/* eslint-disable */` / `eslint-disable-next-line` (eslint).
- `// stylelint-disable` (stylelint).
- `// @ts-ignore` / `// @ts-expect-error` (TypeScript, if ever introduced).
- `<!-- markdownlint-disable ... -->` (markdownlint).

The grep gate runs in pre-commit hooks and in CI on every PR. There are exactly **two** narrow exceptions, both audited:

1. **Test code in `tests/` directories** may use `# type: ignore[attr-defined]` *exactly* when intentionally testing a runtime-only attribute that mypy can't see (e.g., Django `RelatedManager` quirks under specific mocking patterns). Each occurrence requires a one-line comment naming the workaround and a link to a tracking issue. Reviewed at every cycle.
2. **Auto-generated code** (drf-spectacular OpenAPI schema files, Django auto-generated migration files where a manual edit was unavoidable) is excluded by path glob in the linter config, not by inline comments. The path-glob list is short and version-controlled.

If the linter is genuinely wrong in a specific case (rare), the team:

1. Opens an issue documenting the case with a minimal reproducer.
2. Discusses whether to update the linter rule (preferred), introduce a typed wrapper that satisfies the linter (preferred), update the linter version (if it's a bug fixed upstream), or — only as last resort — add a path-glob ignore with a documented expiration date.
3. Never adds an inline `# noqa` or `# type: ignore`.

This discipline is load-bearing for AI-assisted development. AI is tempted to silence the linter rather than fix the underlying issue; forbidding the silencing comments forces the actual fix.

### CI rejects

CI rejects on:

- Formatting drift (Ruff format check).
- Ruff lint violations.
- Missing or incorrect types in strict areas (mypy).
- Pyright/basedpyright failures.
- Forbidden lint-override comments anywhere outside the two narrow exceptions above.
- Test failures at any layer.
- Coverage gates per layer (TDD discipline section above).
- Dependency audit failures (`pip-audit` invoked as `uv run pip-audit` or `uv export --format requirements-txt | pip-audit -r -` — `uv pip audit` is not a real subcommand).
- Security scan failures (Bandit, Semgrep, CodeQL on `main`).
- Permission-snapshot drift without a paired test update.
- Audit-event-emission test failures (missing emission for a documented event is a P0).
- OpenAPI schema drift without a committed-schema update.

## Test-Driven Development Discipline

RackLab is built test-first. This is non-negotiable, and it is particularly load-bearing because most of the implementation will be AI-assisted: tests are the durable contract between AI-generated code and human-defined behavior. AI-generated code can be regenerated, refactored, or swapped — the tests stay. Test-first prevents the failure mode of "AI confidently writes broken code; reviewer doesn't catch it."

Discipline:

- **Write the failing test first.** Every new behavior is preceded by a failing test that captures the requirement. A change that adds a feature without a test that previously failed is rejected in review.
- **Fix the test, not the implementation, when the test is wrong.** If a test passes when it shouldn't, fix the test. If a test fails when the new behavior is intentional, update the test deliberately and document the change in the commit.
- **Belt and suspenders.** The same logic is exercised at multiple layers (unit + contract + integration + E2E where applicable). A bug that slips a unit test gets caught by integration; a bug that slips integration gets caught by E2E. Overlap is the point.
- **Tests are not optional documentation.** They are the executable specification. The PRD describes what; the tests prove what.
- **Mutation testing on critical modules.** RBAC enforcement, quota reservation, the universal `Job` state machine, the Proxmox client task state machine, the SSH plugin's redaction pipeline, and the autoscaler policy engine each have mutation-testing thresholds in CI (run on a nightly schedule, not per-PR, to keep PR CI fast).
- **Coverage gates per layer.** 90% on tiny / unit, 80% on contract, 70% on integration, and explicit named E2E flows for every user-facing journey. Coverage is necessary but not sufficient — mutation testing and named E2E flows backstop it.

## Testing

Four named test layers, all required:

### Tiny (unit)

Pure functions, single classes, no I/O. `pytest`, no `pytest-django` magic where avoidable, no database. Each test runs in milliseconds; the suite is thousands of tests.

- Pure policy and scheduler logic.
- State-machine transitions on `Job` and its subtypes.
- Domain-model invariants (quota math, capability flag arithmetic, plural-form resolution, UPID parsing, asciinema redaction pattern matching).
- Pydantic model validators.
- Permission-string parsing and RBAC predicate logic.

### Contract

Module-boundary tests verifying interface contracts. Use in-memory fakes for collaborators; the unit under test is real.

- Plugin hook specs: each hook is tested with at least one fake plugin that exercises every parameter shape and every documented failure mode.
- `WorkerRuntime` Protocol: both `QuadletWorkerRuntime` and `NomadWorkerRuntime` pass the same Protocol-level test suite. Plugin code is tested against the narrow `PluginWorkerRuntime` Protocol.
- `ProxmoxClient` facade: tests run against the typed facade and assert on the public surface; the `proxmoxer` boundary is tested separately.
- Provider plugin Protocol: every contributed provider plugin runs the same contract suite.
- Console backend Protocol: same.
- DRF serializer round-trip (validation → DB → response) at the API boundary.
- ASGI consumer round-trip (`receive` → handle → `send`) for SSE and Channels.

### Integration

Multi-module flows with real infrastructure pieces. Postgres via the project's `testcontainers` setup; NATS via a per-test JetStream container; fake provider in process.

- Deployment lifecycle: catalog selection → quota reservation → NATS publish → worker pickup → fake provider clone → reconciliation → status reaches `running`. Includes a deliberate worker crash mid-job and verifies the reconciler resumes without re-submitting.
- Plugin lifecycle: install → migrate → enable → run hook → disable → uninstall. Migration rollback verified end-to-end.
- SSE replay: client disconnects mid-stream with `Last-Event-ID = N`; reconnect resumes from `N+1`; events older than the retention window produce the sentinel.
- TLS admin GUI: switch issuance profile triggers the static-config rewrite + Traefik restart; uploaded cert hot-reloads; force-renew rate-limited to 1/hour.
- SSH plugin: `ConsoleAccessGrant` validated, asyncssh connects with pinned host keys, redaction pipeline replaces patterns, abort-on-redaction-failure terminates recording but keeps session live.
- Universal `Job` ledger: every job kind (provider/script/console/notify/reconciler/docs) writes to `Job` with the right subtype and is observable by reconciliation queries.

### End-to-end (E2E)

Full system, browser-driven. Real Postgres, real NATS, real worker fleet (Quadlets in CI), real Traefik, a fake Proxmox (a small Python HTTP server speaking the Proxmox API for the endpoints RackLab uses), a real RackLab core. Browser automation via **Playwright** (preferred for accessibility integration with axe-core) or Selenium where Playwright is impractical.

Named user journeys covered:

- Student logs in, browses catalog, deploys a single VM, opens noVNC console, opens SSH console, runs a script, restores from snapshot, releases the deployment, sees quota return to zero.
- Instructor publishes a catalog item, deploys to a roster, manages a failing student deployment.
- Admin configures a custom ACME issuer, watches first cert issue, switches LE staging → production, force-renews.
- Admin installs a plugin from PyPI, migrates it, enables it, sees a new permission appear in RBAC, disables it, rolls it back, uninstalls.
- Admin uploads a custom theme, switches the deployment to it, sees the login banner change.
- Guest opens a share-link, lands on a deployment detail with redacted references for things they can't see.
- Accessibility: axe-core runs against each critical-flow page during E2E and fails the build on any new violations.

### Frontend (React-island) layers

Required for any React island:

- **Vitest 4** + **React Testing Library** for component unit tests. `vitest-axe` for a11y assertions at the component level.
- **Storybook 10** with the a11y addon. Every Mantine-composed component lands in Storybook before it lands in an application page; the a11y addon catches issues during component dev.
- **Playwright** E2E with `@axe-core/playwright` for the named user journeys (already required at the cross-system layer).
- **Zod v4** schemas for every DRF response shape the React island consumes; failures are explicit, not silent.
- **TypeScript strict** must pass for the React tree (CI gates on `tsc --noEmit`).

Coverage gates for React: 80% on Vitest unit tests; 70% on RTL component-integration tests; named E2E flows for every user journey.

### Cross-layer rules

- Worker tests at every layer use fakes for NATS and provider (tiny/contract) and real for higher layers; fake provider implementations live in `racklab/testing/fakes/`.
- `proxmoxer` is mocked at the contract layer; the real `proxmoxer` boundary is exercised in nightly integration runs against a Proxmox VE test cluster (operator-provided; CI skip if unavailable).
- nsjail script-sandbox tests use real nsjail in CI Linux runners (Linux-only; macOS/Windows dev environments use a smaller fake-sandbox runner).
- Permission regression tests are a contract-layer suite that snapshot the full set of permissions per role and refuses to merge if the snapshot changes without an updated test.
- Audit event tests verify every documented audit event is emitted from the code path that should emit it. Missing audit emission is a P0 bug in this project.

## CI

CI runs on every push and pull request. Each layer is a separate job so failures are diagnosable per-layer; jobs run in parallel where independent.

Required PR-blocking jobs:

- `uv sync --locked` — lockfile integrity.
- Ruff format check.
- Ruff lint.
- mypy with `django-stubs`, `django-stubs-ext`, `djangorestframework-stubs`. Settings configurable per package; strict in plugin contract surfaces and worker payload code.
- Pyright (or basedpyright) strict mode.
- `pytest` tiny layer — no I/O, must complete in under 60s for the whole suite.
- `pytest` contract layer.
- `pytest` integration layer (with `testcontainers`-provided Postgres + NATS).
- Coverage report per layer with the gates defined above; PR fails if any layer drops below its threshold.
- Permission-snapshot test (refuses to merge if the role-permission snapshot changes without an explicit test update).
- Audit-emission test (refuses to merge if a documented audit event has no code path emitting it).
- Dependency audit (`pip-audit` via `uv run pip-audit` — `uv` exposes `pip-audit` as an installed tool, not as a `uv pip audit` subcommand).
- Security scan (Bandit + Semgrep with curated rule packs; CodeQL on push to `main`).
- OpenAPI schema generation check (drf-spectacular schema diff against the committed schema; PR must update the committed schema when the API changes).
- Plugin contract smoke (a hello-world plugin must install/migrate/enable/disable cleanly against the PR's RackLab API).
- TypeScript strict (`tsc --noEmit`) on the React tree.
- Vitest 4 + RTL unit + component tests.
- **Storybook 10 with the Vitest addon + `parameters.a11y.test = 'error'`** so axe checks actually fail the CI run (a vanilla Storybook build will pass even with a11y addon findings — current Storybook docs require the Vitest addon or the test-runner plus the explicit `'error'` parameter for CI failure semantics).
- ESLint (with `eslint-plugin-jsx-a11y`) on the React tree.
- Lingui catalog extract + compile (catalog drift must be committed).
- **Playwright E2E + `@axe-core/playwright`** on the named user journeys (PR-blocking, **promoted from informational**).
- **pa11y** on critical flows (PR-blocking, **new**) — login, deployment create, console open, share-link issue.

Required non-blocking jobs (informational; run in parallel):

- *(none currently — Playwright E2E + axe-core were promoted to PR-blocking; pa11y too, with the React pivot's a11y discipline)*.

Nightly / cron jobs:

- Mutation testing (`mutmut` or `cosmic-ray`) on critical modules listed in the TDD discipline section.
- Integration tests against a real Proxmox VE test cluster (operator-provided; skipped if absent).
- Long-running soak tests for autoscaler stability and the SSE replay window.
- Plugin lifecycle full round-trip (install → migrate → enable → exercise → disable → rollback → uninstall) for every official plugin.

CI is the gate. A PR that fails any blocking job does not merge.
