# RackLab — Agent Orientation

This file is the load-bearing orientation document for AI agents (Claude Code, Codex, Aider, Cursor, Copilot, etc.) working in this repo. Read it first. Cross-referenced from `AGENTS.md` which is the same content under a different filename convention.

## What RackLab is

A self-service educational lab platform replacing RIT's RLES. Django-based control plane on Proxmox VMs. Students and instructors deploy VMs from an instructor-published catalog; admins run the platform. Public repo at `github.com/cyberbalsa/racklab`. Apache-2.0.

The product must scale down to a tiny 1–2 user install and up to thousands of users by separating web, worker, database, event bus, artifact storage, and untrusted script execution onto separate processes.

## Where to read first

**Always start with these, in this order, when working on any feature:**

1. **`docs/prd/`** — the long-term product specification. 23 numbered sections plus two plugin PRDs:
   - `01-executive-summary.md` — what RackLab is at one screen
   - `02-goals-non-goals.md`
   - `03-users-personas.md`
   - `04-full-target-requirements.md`
   - `05-architecture.md`
   - `06-auth-rbac-sharing-tokens.md` — auth + tokens (two-track: signed JWT + opaque PAT)
   - `07-api-openapi-sse.md` — DRF + drf-spectacular + SSE with `Last-Event-ID` replay
   - `08-catalog-stacks-deployments.md` — catalog items (singleton VM) + stack templates (multi-VM) + deployment lifecycle
   - `09-networking.md` — provider networks + `NetworkOffering.reachability`
   - `10-scripting-automation-sandboxing.md` — nsjail, Ansible Runner, openQA-style console scripts
   - `11-quotas-scheduling-placement.md` — OpenStack-triangle quota model, placement
   - `12-proxmox-provider.md`
   - `13-plugin-system.md` — pluggy + lifecycle + **Storage backend contract + 80-hookspec catalog**
   - `14-audit-logging-observability.md` — audit schema, hash chain, `tenant.cross_access` variants
   - `15-ui-ux.md` — **Django + React islands via django-vite + Mantine + Radix gaps + LinguiJS**. File uploads (FilePond chunked). HTMX explicitly out.
   - `16-container-operations.md`
   - `17-engineering-quality-typing-ci.md` — **TDD discipline, no-lint-overrides rule, CI matrix**
   - `18-security.md` — multi-tenancy security, upload security, server-owned access provenance
   - `19-data-model.md` — **Tenant + multi-tenancy at the top**, denormalized `tenant_id`, `RoleBinding.scope_type`, `TokenGrant.scope_type`, `UploadSession`
   - `20-open-questions-risks.md`
   - `21-sources.md`
   - `22-docs-plugin.md` — TipTap via `@tiptap/react` + `@mantine/tiptap`
   - `23-ssh-plugin.md` — `@xterm/xterm` mounted in React; cloud-init host-key phone-home

2. **`docs/roadmap/`** — 22 milestone slices M0 → M13d with explicit acceptance criteria.
   - `README.md` — milestone table + Mermaid dependency graph
   - `M00-foundations.md` — current milestone
   - `M00.5-packaging-runtime-install.md` — installer with 26-flag automatable surface
   - `M10a-ui-component-library.md` / `M10b-a11y-i18n-hardening.md` — M10 was split in this session
   - Each milestone follows the same shape: Goal, In scope, Dependencies, Deliverables, Acceptance criteria, Test layers, Risks/open questions, Out of scope.

3. **`docs/architecture/2026-05-25-django-library-survey.md`** (~70 KB) — the canonical library-adoption reference. Eight research passes + two codex review rounds. Covers security, auth, secrets, scheduled tasks, storage, audit, observability, forms/tables/admin, markdown, asset pipeline, file uploads, multi-tenancy, React stack. **Check this before pinning a new dependency.**

4. **`docs/superpowers/specs/`** — load-bearing design specs:
   - `2026-05-24-proxmox-client-discipline.md` — `proxmoxer` 2.3.0 facade, task-polling discipline, multi-issuer TLS trust
   - `2026-05-24-podman-orchestration.md` — Baseline (Quadlets) + Scale (Nomad) profiles
   - `2026-05-24-server-side-tls-acme.md` — Traefik 3.x, four issuance profiles, `lego` cert agent for Scale

5. **`docs/architecture/diagrams.md`** — Mermaid UML for system component overview, deployment lifecycle, console flow, etc.

6. **`PROGRESS.md`** — what's shipped vs what's next. Updated at the end of every session that lands code or substantive docs.

## Repo layout

```text
.
├── AGENTS.md, CLAUDE.md           — this file (two copies, different filename conventions)
├── PROGRESS.md                    — shipping state + recommended next slice
├── CONTRIBUTING.md, LICENSE
├── pyproject.toml, uv.lock        — uv-managed Python deps + tool config
├── manage.py                      — Django entrypoint
├── .pre-commit-config.yaml        — pre-commit hooks (markdownlint, gitleaks, no-lint-overrides, ruff, mypy, basedpyright, pytest tiny)
├── .github/workflows/             — CI (code-ci.yml + docs-ci.yml)
│
├── docs/
│   ├── prd/                       — PRD §01–§23 + research/
│   │   └── research/              — pre-PRD research notes (NOT normative — see banners)
│   ├── roadmap/                   — M0 → M13d milestones + README
│   ├── architecture/              — library survey, codex review records, diagrams, ADR-like notes
│   └── superpowers/
│       └── specs/                 — load-bearing design specs (Proxmox client, Podman, TLS/ACME)
│
├── src/racklab/                   — installable Python package
│   ├── __init__.py
│   ├── cli.py                     — `racklab` console script entrypoint
│   ├── asgi.py, wsgi.py, urls.py  — Django ASGI/WSGI + URL config
│   ├── settings/                  — base.py / dev.py / test.py / prod.py
│   ├── core/                      — core models + RBAC + audit + plugin lifecycle
│   │   ├── models.py              — Tenant, TenantMembership, Job, Artifact, Permission, Role, RoleBinding, AuditEvent, PluginInstallation, PluginMigrationRecord
│   │   ├── tenancy_bootstrap.py   — idempotent default-tenant + user-backfill helpers (importable from migrations + tests)
│   │   ├── rbac.py                — permission catalog, packs, presets, predicates
│   │   ├── rbac_bootstrap.py      — `sync_rbac_defaults` management command + catalog data
│   │   ├── access.py              — effective-permission resolution
│   │   ├── jobs.py                — Job state-transition services + audit emission
│   │   ├── audit.py               — AuditEvent emitter
│   │   ├── states.py              — JobState, JobKind enums
│   │   ├── plugin_lifecycle.py    — install/migrate/enable/disable/rollback/uninstall state machine
│   │   ├── logging.py             — Django LOGGING config builder (text + JSON formatters)
│   │   ├── management/commands/   — manage.py commands
│   │   └── migrations/            — Django migrations (0001–000N)
│   │
│   ├── plugins/                   — plugin framework
│   │   ├── contracts.py           — plugin manifest contracts
│   │   ├── lifecycle.py           — PluginLifecycleState enum
│   │   ├── hooks.py               — pluggy hookspec definitions + dispatch + NATS fanout
│   │   ├── hello.py               — reference racklab-plugin-hello entry point
│   │   └── hello_app/             — reference Django app shipped by the hello plugin
│   │
│   └── runtime/                   — PluginWorkerRuntime + WorkerRuntime Protocols (concrete impls land in M2 / M12)
│       └── protocols.py
│
└── tests/
    ├── tiny/                      — pure-Python unit tests, no I/O, runs in milliseconds
    ├── contract/                  — module-boundary tests with in-memory fakes
    └── integration/               — testcontainers Postgres + real models
```

## Stack at a glance

**Python / backend:** Django 5.2 LTS, DRF 3.16, drf-spectacular, Channels 4.2, pluggy 1.6, pydantic 2.x, asyncssh 2.x, nats-py 2.x, psycopg 3.x, proxmoxer 2.3.0.

**Auth + tokens:** django-allauth (M1) for users/local/OIDC/SAML; two-track tokens — `djangorestframework-simplejwt` (Track A, RS256, short-lived) + `django-rest-knox` (Track B, opaque PAT, long-lived). Argon2 password hashing via `django[argon2]`.

**Frontend:** Django + React islands via `django-vite` 3.1. Vite 8 + `@vitejs/plugin-react-swc`. **Mantine** (latest stable, currently 9.2.1) for components + **Radix UI** primitives as ARIA fallback. **LinguiJS v6** for translations sharing `.po` catalogs with Django gettext. **TanStack Query v5** (server state) + **Zustand v5** (client state) + **Zod v4** (schema validation). **TypeScript 5.5+ strict, React 19+.** Stock Django admin until M10a lands the custom shell.

**File uploads:** `react-filepond` 7.1.3 frontend + FilePond chunked protocol + Django chunked-receive view (filesystem) or S3 multipart coordinator (S3-compatible backends). See PRD §15 + §18 + §13 Storage backend contract.

**Vanilla JS in React islands:** `@xterm/xterm` 6.x (renamed from `xterm`), `@novnc/novnc` 1.7.x, `@tiptap/react` + `@mantine/tiptap` — all mounted via `useRef` + `useEffect`. Chart.js via `react-chartjs-2` with an `<AccessibleChart>` HOC.

**Deployment:** Podman dual-profile — Quadlets (Baseline, single host) + Nomad with Podman driver (Scale, multi-host). Traefik 3.x in front for TLS. NATS JetStream for messaging.

**Observability:** `django-prometheus` + `sentry-sdk` + `django-health-check` + (optionally, M13b) OpenTelemetry. M2 in-product graphs use plain Postgres + BRIN indexes + materialized rollups + Chart.js; TimescaleDB only after a spike proves the bottleneck.

**Testing:** pytest + pytest-django + factory-boy + testcontainers. Vitest + RTL + Storybook + vitest-axe + Playwright + axe-core for the React tree.

**Dev tooling:** uv (package manager + lockfile), ruff (format + lint, all rules on, no overrides), mypy strict, basedpyright, bandit, semgrep, pip-audit, pre-commit.

## Multi-tenancy primer (load-bearing)

Resource hierarchy: **Tenant → Project → Deployment ([Stack | Ad-Hoc VM]) → DeploymentResource.** Course is orthogonal — it's a membership/access-control concept, not a containment level.

**Soft isolation, RBAC-enforced.** One Postgres, one migration graph, one backup. Tenant context propagates via `contextvars` (not thread-locals) for ASGI/Channels/NATS-worker safety. Background workers carry explicit `tenant_id` on every NATS envelope.

**Two cross-tenant dimensions, both audited:**

- **Resource visibility** — each tenant-scoped resource declares `sharing_scope`: `tenant_local` (default) / `shared_with_tenants=[...]` / `global`.
- **Actor scope** — `RoleBinding.scope_type`: `tenant_local` (default) / `multi_tenant` (with `tenant_set`) / `global`. Issuance is contained: granter must hold ≥ granted scope.

**Permission check composes three predicates:** binding scope ⊇ resource tenant AND resource visibility ⊇ actor tenant AND role ⊇ requested action. All three must pass.

**`tenant.cross_access` audit event** fires on every cross-tenant access (access variant) and every cross-tenant binding/token/share-link issuance (issuance variant). Bidirectional surfacing: actor's tenant + resource owner's tenant + every tenant in `target_tenant_set` see the event.

**Denormalized `tenant_id`** on hot tables (`Job`, `Artifact`, `Deployment`, `Reservation`, `AuditEvent`) — immutable at insert, indexed first in composite indexes.

## Plugin system primer

`pluggy` 1.6 + Python entry points. Plugin lifecycle is a state machine: `installed → migrated → enabled → disabled → pending_uninstall` with CLI commands `racklab plugin install/migrate/enable/disable/rollback/uninstall`.

Plugins extend RackLab through ~80 hookspecs grouped by domain (PRD §13 Hookspec Catalog): storage, auth, tokens, RBAC, tenant, deployment, job, quota, provider, networking, console, SSH, scheduler, catalog, audit, notification, i18n, UI, docs, health, TLS, webhooks, plugin lifecycle. Hookspec naming: `racklab_<domain>_pre_<verb>` / `racklab_<domain>_post_<verb>` / `_resolver` / `_validator` / `_contributor` / `_sink`.

**Storage backend is a plugin family** — core ships filesystem; S3/GCS/Azure/MinIO are plugins implementing the `ArtifactBackend` Protocol. First-party: `racklab-storage-proxmox-shared` tunnels artifact bytes onto the Proxmox cluster's shared storage (CephFS / NFS / GlusterFS / ZFS-over-iSCSI via `pvesm`).

## Engineering discipline (load-bearing)

**TDD per PRD §17** is non-negotiable, particularly because most implementation is AI-assisted: tests are the durable contract between AI-generated code and human-defined behavior.

- **Write the failing test first.** Every new behavior is preceded by a failing test that captures the requirement.
- **Belt and suspenders.** Tiny + contract + integration + E2E. Overlap is the point.
- **Coverage gates per layer.** 90% tiny, 80% contract, 70% integration, named E2E flows for every user journey.
- **Mutation testing** on RBAC, quota, Job state machine, Proxmox task state machine, SSH redaction, autoscaler — nightly, not per-PR.

**No-overrides linter discipline.** No `# noqa`, no `# type: ignore`, no `// eslint-disable` in production code. Two narrow audited exceptions (test code `# type: ignore[attr-defined]` for runtime-only attributes; auto-generated code excluded by path glob). If the linter is wrong, fix the underlying code or the linter rule, not the source.

**Permission-snapshot test** refuses to merge PRs that change a role's permission set without updating the snapshot. **Audit-emission test** refuses to merge PRs that document a new audit event without a code path emitting it. **`@untenanted` CI gate** refuses to merge models without a `tenant` FK unless explicitly decorated.

**Codex review pattern.** For substantive design specs and PRD edits, a codex review fires before commit. Pattern:

```bash
tmpfile=$(mktemp /tmp/codex-review.XXXXXX.md)
codex exec --dangerously-bypass-approvals-and-sandbox --skip-git-repo-check \
  "Review <target>. ..." > "$tmpfile" 2>&1 &
# read tmpfile after completion, fold P0/P1 findings, commit
```

For PRD edits, the established pattern is "propose wording before applying" — show the actual proposed text in a code block, get directional approval, then `Edit`.

## Commit conventions

**Conventional Commits.** `feat:` / `fix:` / `chore:` / `refactor:` / `docs:` / `test:` / `perf:` / `build:` / `ci:` / `style:`. Imperative mood, lower-case subject, no trailing period. Optional scope: `feat(core): add Tenant model`. Body explains *why* when non-obvious.

**Signed commits mandatory** via the local SSH signing config (Bitwarden agent on the development laptop). **Never use `--no-verify`, `--no-gpg-sign`, or `-c commit.gpgsign=false`.** If a pre-commit hook fails, fix the underlying issue and create a NEW commit (never amend after a hook failure — the commit didn't happen).

**Small logical chunks.** Commit at natural breakpoints — feature complete, tests passing, before a risky refactor. Don't bundle unrelated changes.

**Never force-push or `reset --hard` shared branches** without explicit approval.

## What NOT to do

- **Don't fabricate APIs, version numbers, or config keys.** Look them up — official docs > installed source > tests. If unsure, say "I don't know, let me check."
- **Don't claim "done" without verification.** Run the type checker, linter, tests. For UI changes, exercise the feature in a browser. Partial success is fine; silent partial success is not.
- **Don't introduce scope creep.** Do what was asked, nothing more. No surprise refactors, no speculative abstractions, no "while I was in there" cleanups.
- **Don't bypass the audit / permission / quota / tenant checks** in models or views — those are load-bearing and the CI gates will catch you.
- **Don't add `# type: ignore` or `# noqa`** — fix the type or the linter rule instead.
- **Don't write documentation files** unless explicitly requested.
- **Don't sleep / poll** when waiting for background work — the harness will notify you on completion. Long sleeps wreck the prompt cache.

## Operational notes

- **Python 3.12+** is required (3.13 + 3.14 also tested). Manage via uv.
- **uv** is the canonical package manager. `uv sync --locked` installs from the lockfile; `uv lock` updates it; `uv add` adds deps. Run tools as `uv run <tool>`.
- **Pre-commit hooks** run ruff, mypy, basedpyright (on the strict contract surface), markdownlint, gitleaks, and the no-lint-overrides check. Run `uv run pre-commit run --files <paths>` before commit.
- **Tests:** `uv run pytest` runs all layers. `pytest -m tiny` for the fast loop. `pytest -m integration` for testcontainers-backed integration.
- **Dev server:** `uv run python manage.py runserver` (after `migrate`). Settings module is `racklab.settings.dev` by default in dev; `racklab.settings.test` in pytest.

## Asking the user

- Be decisive — propose a recommendation with the main tradeoff in 2–3 sentences for exploratory questions; don't ask for direction when the path is clear.
- Use `AskUserQuestion` only when there's a real decision point with multiple valid answers. Make the first option the recommendation when one exists.
- For PRD edits, show proposed wording in a markdown code block before applying.

## When in doubt

Read `docs/prd/` and `docs/architecture/2026-05-25-django-library-survey.md`. They're the source of truth for *what* and *with what*. The roadmap is the source of truth for *when*. This file is the index — start here, follow the links.
