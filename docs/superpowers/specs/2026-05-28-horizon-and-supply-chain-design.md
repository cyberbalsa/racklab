# Horizon install + supply-chain hardening

**Date:** 2026-05-28
**Status:** Draft — awaiting user approval
**Authors:** Forrest Fuqua + Claude (auto-agent)

## Why now

The MVP loop is otherwise closed. PROGRESS.md's "Next" section tracks four items, three of which turn out to be stale or trivial on inspection:

1. **`laravel/horizon` Illuminate-13 compat** — already unblocked. Packagist publishes `laravel/horizon v5.47.1` with `illuminate/contracts ^9.21|^10.0|^11.0|^12.0|^13.0`. A `composer require --dry-run` resolves cleanly against the current lockfile.
2. **`enlightn/security-checker` Symfony-8 compat** — was never load-bearing. It scans `composer.lock` against the FriendsOfPHP advisory DB. `composer audit` (already in CI) uses the same database and ships with Composer 2.4+. The "blocker" is a redundant tool no longer worth pinning. The package's latest v2.0.0 was released 2023-12 and has had no upstream movement.
3. **GHA Node 20 deprecation** — already resolved. All workflows use `actions/checkout@v6`, `actions/setup-node@v6`, `actions/setup-python@v6`, `actions/upload-artifact@v4`, `docker/setup-buildx-action@v3`. Zero Node-20-era refs remain.
4. **`fabpot/local-php-security-checker` (considered as a replacement)** — archived 2024-08, do not adopt.

This spec covers the substantive change (Horizon wire-up) and the supply-chain hardening that the audit exposed (Dependabot + image CVE scanning). The remaining MVP-closure items (real baseline-worker-host soak, podman-runtime-ci against a self-hosted runner) need external infra and are out of scope for this spec; this spec preps the runner-registration helpers as a sibling deliverable.

## Goals

- Install `laravel/horizon` and replace the temporary `php artisan queue:work` Quadlets with a Horizon-supervised topology, matching the redesign spec §05 and PRD §05/§11.
- Gate the Horizon dashboard behind `AccessResolver` with a new `horizon.view` permission (admin + support only). No raw Spatie role checks.
- Add `.github/dependabot.yml` for composer, npm, github-actions, and docker ecosystems.
- Add Anchore Grype to `.github/workflows/build-images.yml` so the Syft SBOMs already being generated are scanned for CVEs.
- Clean the stale PROGRESS.md / docs/prd/17 notes that the audit invalidated.
- Prep a `scripts/dev/register-host-runner.sh` + systemd-user unit template so a self-hosted GHA runner can be registered with one paste of a registration token. The token paste itself is out of scope.

## Non-goals

- Pulse / Telescope wire-up. Pulse is the future Horizon-companion observability surface (PRD §14, M13b). This spec installs Horizon only.
- Scale-profile (Nomad) Horizon topology. Baseline only. The Quadlet structure here is single-instance.
- Horizon-driven `racklab:ops-smoke` replacement. Current ops-smoke uses `QUEUE_CONNECTION=null` as a stopped-worker proxy. Re-pointing it at real Horizon is the next slice, paired with the soak.
- Replacement of `composer audit` or removal of Semgrep/Roave/`racklab:security-check`. Those stay. Grype + Dependabot are additive.
- Trivy. We pick Grype because it shares Anchore's Syft pipeline already in use; switching pipelines is a separate decision.

## Stack at a glance

| Slot | Pick | Version pin |
|------|------|-------------|
| Queue supervisor | `laravel/horizon` | `^5.47` (v5.47.1) |
| Horizon Redis connection | existing `redis` connection from `config/queue.php` | unchanged |
| Auth gate | `App\Auth\HorizonAuthGate` invoked via `HorizonServiceProvider::gate()` | new |
| New permission | `horizon.view` | added to `DefaultRoleCatalog` for `admin` and `support` |
| Dependency-update bot | GitHub Dependabot | native, config-driven |
| Image CVE scan | `anchore/grype` | latest stable in CI (no PHP dep) |
| Image SBOM (already installed) | `anchore/syft` | unchanged (v1.44.0 pinned) |
| Runner registration | `actions/runner` v2 (downloaded by helper script) | latest at runtime |

## Design

### 1. Horizon install

- `composer require laravel/horizon ^5.47`. The lockfile gains `laravel/horizon v5.47.1` and `laravel/sentinel v1.1.0` (Horizon's auth helper).
- `php artisan horizon:install` publishes:
  - `config/horizon.php`
  - `app/Providers/HorizonServiceProvider.php` (Laravel stub; we rewrite it)
  - `public/vendor/horizon/` assets (committed)
- `composer.json` gains no Composer auto-discovery suppression — Horizon's package is an internal piece of RackLab and benefits from normal Laravel discovery. (This is distinct from RackLab plugins, which suppress discovery; see PRD §13.)
- Add to `bootstrap/providers.php` (Laravel 13's provider registry): `App\Providers\HorizonServiceProvider::class`.
- Add `pcntl` and `posix` extension probes to `composer.json`'s `require` section as explicit `ext-pcntl` and `ext-posix`. These are present on every Linux PHP-CLI build but absent on Windows; the require makes the dependency explicit and Composer-checkable.

### 2. Supervisor topology

`config/horizon.php` declares four named supervisors that match the existing **job-bearing** queue names verbatim, so jobs already enqueued through `RunUserScript`, `RunAnsiblePlaybook`, `RunConsoleScript`, `RunFakeProviderTask`, and `PollProxmoxTask` continue to route correctly:

| Supervisor | Queue(s) | `balance` | `processes` baseline | `tries` | `timeout` | `maxTime` |
|------------|----------|-----------|----------------------|---------|-----------|-----------|
| `racklab-provider` | `provider,default` | `auto` | 3 | 1 | 300 s | 3600 s |
| `racklab-scripts` | `scripts,cleanup` | `auto` | 4 | 1 | 900 s | 3600 s |
| `racklab-console` | `console` | `simple` | 1 | 1 | 3600 s | 3600 s |
| `racklab-notifications` | `notifications,default` | `auto` | 2 | 3 | 120 s | 3600 s |

Three environments are configured (`production`, `local`, `testing`). `testing` sets `processes=1` and `balance=simple` everywhere so Pest's `pest:integration` boots Horizon deterministically.

The existing `racklab-scheduler-reconciler@.container` runs a `while true` shell loop directly invoking artisan commands (`racklab:reconcile-provider-tasks`, `racklab:expire-deployments`, `racklab:detect-provider-drift`, `racklab:reap-script-containers`). That container is **intentionally untouched** by this slice. Wrapping those commands as queue-dispatched Job classes and moving the cadence into Laravel's `withSchedule()` callback is a follow-up slice with its own design pass — coupling it to the Horizon install would balloon scope. The four supervisors above cover all queues already in use; no `reconciler` queue is added.

### 3. Auth gate

`App\Auth\HorizonAuthGate` exposes a single `authorize(?User $user): bool` method. It:

1. Refuses anonymous (`null`) callers.
2. Resolves the user's active tenant via `TenantContextStore`.
3. Calls `AccessResolver::check($actor, 'horizon.view', $tenantScopedResource)` against a `TenantScopedResource` wrapper representing the platform-wide Horizon dashboard. The dashboard is bound to a sentinel tenant scope: `RoleBindingScopeType::Global`, `sharing_scope=tenant_local`, `tenant_id=actor's active tenant`. Effectively: the user needs the permission and an active tenant.
4. Logs an `audit_events` row with type `horizon.access` (allowed) or `horizon.access.denied` (denied), via the existing `AuditEventWriter`.

`HorizonServiceProvider::gate()` registers a closure that calls `HorizonAuthGate::authorize()`. The Laravel-stub Gate-based default is replaced.

### 4. Permission catalog change

Add `horizon.view` to:
- `app/Domain/Rbac/Permission.php` (canonical enum).
- `app/Domain/Rbac/DefaultRoleCatalog.php`: `admin` and `support` only. Not `instructor`, `ta`, or `student`.
- `tests/Snapshots/roles.json`: regenerated; PR fails if the snapshot is not updated alongside the catalog (existing snapshot gate).

### 5. Quadlet refactor

- **Delete** (in this commit): four worker-pool Quadlets at `deploy/quadlets/racklab-provider-worker@.container`, `racklab-script-worker@.container`, `racklab-console-worker@.container`, `racklab-notification-worker@.container`.
- **Keep** (unchanged): `deploy/quadlets/racklab-scheduler-reconciler@.container`. Its `while true` artisan-command loop continues unchanged in this slice. Job-class wrapping is the follow-up slice.
- **Add**: one `deploy/quadlets/racklab-horizon.container` running `php artisan horizon`. `StopSignal=SIGTERM`, `StopTimeout=3700s` (≥ the longest individual queue timeout, console, 3600s + grace). Mounts match the union of the deleted worker Quadlets so script/console jobs still see the host Podman socket. `Environment=RACKLAB_HORIZON=1`.
- **Update** `deploy/quadlets/racklab-runtime.target`'s `Wants=` line to swap the four worker units for `racklab-horizon.service`; keep `racklab-scheduler-reconciler@1.service` in place.
- **Update** `scripts/baseline-install.sh` to render the new Quadlet set on install/upgrade. The installer must remove the four old worker units cleanly on upgrade (write+verify-disable+rm) so installs that upgraded from a pre-Horizon Baseline don't leave stale units. Idempotent.
- Container images: the existing `provider-worker`, `script-worker`, `console-worker`, `notification-worker` Containerfile targets are merged into a single `horizon` target; `scheduler-reconciler` target stays as-is. The build-images workflow's target matrix shrinks from seven to four (`web`, `reverb`, `horizon`, `scheduler-reconciler`). The old per-pool image tags continue to publish for one release cycle as identical mirror tags pointing at the same `horizon` image, so any external integrators pulling the old names don't break instantly.

The single Horizon container forks per-pool processes internally according to `config/horizon.php`. PRD §05's "Horizon worker pools (separate processes)" is satisfied: each supervisor block fires `processes=N` forked workers under Horizon's master.

### 6. Dependabot

`.github/dependabot.yml`:

```yaml
version: 2
updates:
  - package-ecosystem: composer
    directory: /
    schedule: { interval: weekly, day: monday }
    groups:
      php-minor-patch: { update-types: [minor, patch] }
    commit-message: { prefix: "build(deps)" }
    open-pull-requests-limit: 10
  - package-ecosystem: npm
    directory: /
    schedule: { interval: weekly, day: monday }
    groups:
      js-minor-patch: { update-types: [minor, patch] }
    commit-message: { prefix: "build(deps)" }
  - package-ecosystem: github-actions
    directory: /
    schedule: { interval: weekly, day: monday }
    commit-message: { prefix: "ci(deps)" }
  - package-ecosystem: docker
    directories: [/]
    schedule: { interval: weekly, day: monday }
    commit-message: { prefix: "build(deps)" }
```

Commit-message prefixes are Conventional-Commits-compliant. `npm` directory tracks the root `package.json`. `docker` ecosystem follows the `FROM` lines in `Containerfile`.

### 7. Grype in build-images.yml

After the existing `Generate SBOM` step (per target image), add:

```yaml
- name: Scan SBOM with Grype
  uses: anchore/scan-action@v6
  with:
    sbom: ${{ runner.temp }}/sbom-${{ matrix.target }}-cyclonedx.json
    fail-build: true
    severity-cutoff: high
    only-fixed: true
    output-format: sarif
    output-file: grype-${{ matrix.target }}.sarif
- name: Upload Grype SARIF
  if: always()
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: grype-${{ matrix.target }}.sarif
    category: grype-${{ matrix.target }}
```

`only-fixed: true` keeps the gate from failing on CVEs with no upstream fix (which would otherwise block on base-image lifecycle, not RackLab code). `severity-cutoff: high` matches the existing `npm audit --audit-level=high` discipline.

`.github/grype.yaml` mirrors the license-policy allowlist pattern: a documented exception model where any explicit ignore-rule cites the CVE id, the package, the rationale, and an expiration date (so allowlist entries don't outlive the underlying fix). Initial allowlist is empty; entries appear only as concrete CVEs require them.

### 8. PROGRESS.md / PRD doc cleanup

- PROGRESS.md "Next" section: remove the Horizon-blocked claim from item #1, the Node-20 mention from item #3, the enlightn paragraph; add a Horizon-shipped block to the body. Rewrite item #3 ("ci-gates") to reflect the actual state.
- `docs/prd/17-engineering-quality-typing-ci.md` (the engineering/quality file): the Horizon dependency note is already correct; add a one-line note that Grype is the SBOM CVE scanner.
- `CLAUDE.md` + `AGENTS.md`: update the stack table's Queue+jobs row from "v5.47" to "v5.47.1 (installed)"; no other change.

### 9. Self-hosted runner prep

- `scripts/dev/register-host-runner.sh`: prompts for the registration token (or accepts `--token=`), downloads `actions/runner` v2 to `~/actions-runner`, runs `./config.sh --url https://github.com/cyberbalsa/racklab --token … --labels self-hosted,linux,podman,cgroup-delegated --unattended`. Idempotent — refuses to overwrite an existing runner config without `--reconfigure`.
- `scripts/dev/racklab-self-hosted-runner.service.template`: systemd-user unit so the runner survives reboots. Installer copies it to `~/.config/systemd/user/`, runs `systemctl --user daemon-reload && systemctl --user enable --now racklab-self-hosted-runner.service`.
- Locked by `tests/Integration/SelfHostedRunnerScriptTest.php`: checks the script exists, contains the required labels in order, refuses without a token, refuses without `--reconfigure` when a config already exists.

The token paste itself is out of scope for the spec — the user generates a token at `github.com/cyberbalsa/racklab/settings/actions/runners/new` and invokes `scripts/dev/register-host-runner.sh --token=…`.

## Test plan

The PRD §17 belt-and-suspenders TDD discipline applies. Test write order matches the implementation order in `## Rollout` below.

### Tiny

- `HorizonAuthGateTest`: anonymous denied; user without `horizon.view` denied; user with `horizon.view` allowed; user with permission but no active tenant denied; ensures `AuditEventWriter` is called with `horizon.access` / `horizon.access.denied`.
- `HorizonConfigShapeTest`: parses `config/horizon.php` and asserts the five supervisor blocks, queue names, `tries`, `timeout`, `maxTime`, environment specialization.
- `DependabotConfigTest`: parses `.github/dependabot.yml`, asserts presence of the four ecosystems, commit-message prefixes, schedule.
- `BuildImagesWorkflowTest` extension: existing test, gain `Scan SBOM with Grype` step assertion + SARIF upload.

### Contract

- `HorizonDashboardAccessTest`: `GET /horizon` as admin → 200; as student → 403; as anonymous → redirect to login; admin attempt emits `horizon.access` audit; denial emits `horizon.access.denied`.
- `SelfHostedRunnerScriptTest`: script shape (see §9).
- `BaselineInstallScriptTest` extension: install renders the new `racklab-horizon.container`; upgrade removes the five old worker units cleanly.

### Integration

- `HorizonWorkerSmokeTest` (`tests/Integration`): boots a real Redis (testcontainers Redis 7), starts Horizon with `php artisan horizon:work` against the testing-environment supervisor map, dispatches a `RunUserScript` against the `FakeContainerRuntime`, asserts the job completes through Horizon's path, asserts the `script_runs` ledger row is created. Skips when Redis is not available in the environment.
- `PostgresMigrationBehaviorTest` and `PodmanRuntimeIntegrationTest` continue to skip the same way they do today.

### Snapshot

- `tests/Snapshots/RolePermissionsTest.php` re-runs; `roles.json` must contain `horizon.view` for `admin` and `support`.
- `tests/Snapshots/AuditEventsTest.php` re-runs; `audit-events.json` must contain `horizon.access` and `horizon.access.denied`, each backed by a contract test.

### Browser

- Extend `tests/Browser/FilamentAdminWorkflowTest.php`: admin clicks a "Horizon" link from the Filament tenant panel and lands on `/horizon` with the dashboard rendered. `axe-core` passes.
- Non-admin browser path stays denied (covered at the contract layer; no need to drive Dusk for the negative case).

## Rollout

1. Compose the design (this file).
2. **Tiny tests first.** `HorizonAuthGateTest`, `HorizonConfigShapeTest`, `DependabotConfigTest`, `BuildImagesWorkflowTest` extension. Red.
3. Install Horizon + publish config + write `HorizonAuthGate`. Tiny tests turn green.
4. Contract test: `HorizonDashboardAccessTest`. Red.
5. Wire `HorizonServiceProvider::gate()`. Contract turns green.
6. Snapshot tests turn red on permission catalog change. Update `DefaultRoleCatalog` + `roles.json`. Green.
7. Audit-event snapshot: add `horizon.access` events to `audit-events.json`. Green.
8. Quadlet refactor: write `racklab-horizon.container`, delete five old units, update `racklab-runtime.target`. Extend `BaselineInstallScriptTest`. Green.
9. Integration test `HorizonWorkerSmokeTest`. Green if Redis available; skipped otherwise (same pattern as Podman integration today).
10. `.github/dependabot.yml`.
11. Grype step in `build-images.yml` + `.github/grype.yaml`. Workflow-shape test extension.
12. `scripts/dev/register-host-runner.sh` + systemd-user unit template. Script test green.
13. PROGRESS.md + CLAUDE.md + AGENTS.md + `docs/prd/17` cleanup.
14. Browser test extension.
15. **Codex review** of the entire branch (`codex review --uncommitted`). Address P0/P1 findings before commit.
16. Single conventional-commit per logical chunk: `feat(queue)`, `chore(deps)`, `ci(images)`, `docs`, `chore(deploy)`. Signed via the Bitwarden SSH agent.

## Risk register

- **Horizon's pcntl/posix requirements.** Linux PHP-CLI has both; the Containerfile already produces a Linux image. Local dev on macOS still requires Homebrew PHP with the extensions, but this repo is Linux-first. Acceptable.
- **Horizon's broadcast events** can fire on its own pub/sub channels. We need `BROADCAST_CONNECTION` configured during Horizon worker boot — already set to `null` in `testing` and to `reverb` in production via `.env.example`. No change.
- **`laravel/sentinel` v1.1.0 transitive dep** — published 2024-12 by Laravel, MIT, used by Horizon for auth helpers. No conflict with existing deps.
- **Grype false positives on the base image.** `only-fixed: true` + `severity-cutoff: high` + a documented allowlist absorbs this. The allowlist file lives next to license-policy and follows the same review discipline.
- **Stale Quadlet units on Baseline upgrades.** Installer must remove the five old worker units cleanly. Tested by `BaselineInstallScriptTest`.
- **Octane state-leak for Horizon-fired jobs.** Horizon's worker processes are separate from Octane request workers; the leak risk is identical to what `BindTenantContext` already covers (every dispatched job carries `tenant_id` on its payload envelope). No new code required.

## Out of scope (deferred)

- Pulse + Telescope integration. → M13b observability.
- Nomad / Scale-profile autoscaling on Horizon queue depth. → M12.
- A new `racklab:ops-smoke --use-horizon` path that drains real Horizon workers. → The next slice, paired with the self-hosted-runner soak.
- Wrapping `racklab:reconcile-provider-tasks` / `racklab:expire-deployments` / `racklab:detect-provider-drift` / `racklab:reap-script-containers` as Horizon-dispatched Job classes driven by `bootstrap/app.php`'s `withSchedule()` callback. → A follow-up reconciler-refactor slice. The existing `racklab-scheduler-reconciler@.container` keeps its `while true` shell loop until then.
- SLSA L2/L3 build provenance attestations on published images. → Future supply-chain hardening pass.
- Replacement of `composer audit`. Stays.

## References

- Laravel Horizon docs: https://laravel.com/docs/horizon
- Redesign spec §05/§07/§11/§17: `docs/superpowers/specs/2026-05-26-laravel-redesign.md`
- PRD §05 architecture, §17 engineering: `docs/prd/05-architecture.md`, `docs/prd/17-engineering-quality-typing-ci.md`
- Anchore Grype: https://github.com/anchore/grype
- GitHub Dependabot v2 schema: https://docs.github.com/en/code-security/dependabot/working-with-dependabot/dependabot-options-reference
