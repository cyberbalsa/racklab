# RackLab Progress

Tracks what has shipped vs what is next.

## Stack direction

RackLab is being built on **PHP 8.3+ / Laravel 13 + FrankenPHP + Octane + Livewire 4 + Filament 5 + Tailwind v4 + daisyUI 5 + Reverb + Horizon + Podman job containers**. The architectural source of truth is `docs/superpowers/specs/2026-05-26-laravel-redesign.md`. The PRD (`docs/prd/`) remains the source of truth for *what* RackLab does.

## Shipped

### prd-rewrite sub-plan (2026-05-26 → 2026-05-27)

The first of seven sub-plans from the redesign-spec §10 portfolio is complete. Every PRD section, every roadmap milestone, the architecture diagrams, `CLAUDE.md`/`AGENTS.md`, and the still-applies Podman + Proxmox-client-discipline specs have been rewritten or updated to reflect the Laravel stack.

Highlights:

- **`docs/superpowers/specs/2026-05-26-laravel-redesign.md`** — architectural spec authored after two rounds of codex review (research review for ecosystem-state, spec review for internal consistency). Captures stack table, process topology, repo layout, multi-tenancy + RBAC composition, plugin model, script execution + real-time, quality + CI.
- **All 8 heavy PRD-section rewrites** committed (§05 architecture, §06 auth/RBAC/tokens, §07 API/OpenAPI/real-time push, §13 plugin system, §15 UI/UX, §17 engineering/quality/CI, §22 docs plugin, §23 SSH plugin).
- **5 light PRD-section sweeps** (§10 scripting/sandboxing, §14 audit/observability, §18 security, §19 data model, plus a catch-all sweep across the remaining 12 less-affected sections).
- **All roadmap milestones rewritten** (M00 → M13d + README; now 23 slices after adding M5c VPNaaS), preserving each milestone's functional Goal / Acceptance criteria and rewriting Deliverables / Test layers / Risks for the Laravel stack.
- **Architecture Mermaid diagrams** updated across 8 diagram blocks.
- **Two systemic remediation sweeps** caught problems earlier per-task implementers missed:
  - NATS-removal sweep (commit `c074571`) — replaced NATS / NATS JetStream references across ~25 files with Redis + Horizon + Reverb + Postgres `broadcast_event_log` + outbox-table equivalents.
  - Codex full-review remediation (commit `b2f59db`) — applied 50+ P0 + 30+ P1 findings across the entire docs tree, including a full PHP-stack rewrite of the body of `docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md` (the header carry-forward note alone was not sufficient).

### laravel-scaffold foundation slice (2026-05-27)

The Laravel scaffold is now through the initial quality-gate slice:

- Pest 4 is installed and `composer test` runs the PRD layer split (`Tiny`, `Contract`, `Integration`, `Browser`) instead of Laravel's default `Unit` / `Feature` split.
- Larastan is installed at PHPStan max level with the six custom RackLab rule stubs registered and passing under `composer larastan`.
- Rector is installed with a Laravel-oriented baseline, and `composer rector:dry` passes.
- `racklab/plugin-hello` exists as an in-monorepo Composer path package with RackLab plugin metadata and no Laravel package auto-discovery provider/alias entries; it is required by the root project but explicitly excluded from Laravel discovery.
- The stock Laravel welcome screen was replaced by a small RackLab scaffold page using the configured Vite entries, and the contract smoke test covers it without requiring built assets.
- A Livewire 4 `/hello` smoke component exists and is covered by a Tiny test. Dusk 8 + axe-core browser tests cover the same page and are wired for CI; local execution currently requires installing a Chrome/Chromium binary.
- Lefthook is installed through npm and configured for pre-commit (`pint`, `larastan`, `rector`, Tiny Pest) and pre-push (`composer test`, `npm run build`, dependency audits) gates.
- `.github/workflows/code-ci.yml` runs PHP 8.3/8.4 quality gates, asset build/audit, and a Dusk browser job on GitHub-hosted Ubuntu runners.
- Generated Laravel / Filament / Vite artifacts are ignored so normal Composer and frontend commands do not leave runtime outputs in git status.

## Next

Five remaining sub-plans from the redesign-spec §10 portfolio:

1. **`tenancy-auth`** — `app/Domain/Tenancy/AccessResolver`, `CrossTenantFetch`, `IdentifyTenant` + `SetTenantContextForOctane` + `BindTenantContext` middleware, `RoleBinding` model with `scope_type` + `tenant_set`, spatie/laravel-multitenancy + spatie/laravel-permission integration, Filament tenancy with `isPersistent: true`. Track A JWT issuer + JWKS endpoint + Sanctum PATs + Fortify + Socialite + OIDC + SAML. `AuditEvent` three-tenant schema + hash chain + `racklab:verify-audit-chain` Artisan command + bidirectional surfacing query.
2. **`plugin-lifecycle`** — `PluginRegistry`, `PluginInstallation` + `PluginMigrationRecord` models, `racklab plugin install/migrate/enable/disable/rollback/uninstall` Artisan commands, `HookDispatcher` with the four listener-style semantics, hookspec event class scaffold, `racklab/plugin-hello` reference implementation.
3. **`realtime-replay`** — Reverb daemon, channel auth, `broadcast_event_log` table + `ShouldBroadcast` events that implement Laravel's after-commit dispatch discipline, `/api/v1/replay` endpoint + sweep job. xterm.js + noVNC islands. Negative-path tests for replay gap sentinel.
4. **`script-containers`** — Horizon worker setup (pcntl/posix), `RunAnsiblePlaybook` + `RunUserScript` + `RunConsoleScript` job classes, container manifests, `ProviderConsoleProxy` unix-socket service, container image build pipeline (cosign-signed), reaper sidecar. Provider-task idempotency port from `2026-05-24-proxmox-client-discipline.md`.
5. **`ci-gates`** — complete the remaining gates beyond the scaffold: snapshot tests, OpenAPI schema-drift gate, semgrep + security-checker, richer custom Larastan rule behavior beyond the registered stubs, and `racklab:lang:check` i18n drift.

Sub-plans 1 → 5 can now proceed on top of the Laravel scaffold. `tenancy-auth` is the recommended next slice because tenant context, RBAC, and audit provenance are prerequisites for most user-facing behavior.
