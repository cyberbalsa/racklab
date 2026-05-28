# Horizon install + supply-chain hardening

**Date:** 2026-05-28
**Status:** Draft v3 — awaiting user approval. v2 codex review surfaced five more P1 findings (all confirmed real). v3 folds them.
**Authors:** Forrest Fuqua + Claude (auto-agent)

## Changelog vs v2

v2 codex review (`/tmp/codex-horizon-spec-v2.Wu10Od.md`) flagged five P1s; v3 addresses each:
- `permittedPlatform()` over-authorized any global binding regardless of resource. v3 introduces a single `platform:racklab` resource; bindings must target it explicitly.
- Console queue mismatch survived v2: `RunConsoleScript` extends `RunScriptContainer` whose constructor dispatches `script-worker`. v3 has `RunConsoleScript::__construct()` override the queue to `console-worker`, plus a queue-name regression test.
- `REDIS_QUEUE_RETRY_AFTER` was bumped in `.env.example` only — Baseline installer never rendered it, so production stayed at the unsafe default of 90. v3 changes the `config/queue.php` default to 3700 so it's safe-by-default regardless of `.env`.
- Anonymous `/horizon` access returns 403 (not a `/login` redirect) because Horizon's middleware is `web`-only. v3 accepts the 403 (with audit row) — adding `auth` would bypass the audit gate. Contract test expectation updated.
- Plugin volume was writable in both new Horizon Quadlets. v3 mounts plugins read-only (`:ro,Z`) for both runtime containers; only `racklab-plugin-bootstrap` retains write access.
- v2 P2s also folded: RetryAfter test iterates resolved per-env supervisors; SARIF upload step gains `continue-on-error: true` for fork-PR safety.

## Changelog vs v1

- **v1** described single-Horizon-container topology with queue names `provider`, `scripts`, `console`, `notifications`, a tenant-aware HorizonAuthGate, and one Grype scan step.
- **v2** addresses the v1 codex review:
  - Queue names now match the actual job dispatches (`provider-worker`, `script-worker`, `console-worker`, `notification-worker`).
  - Two Horizon containers split by Podman-socket exposure (privilege boundary).
  - `AccessResolver::permittedPlatform()` for platform-scope authorization; bootstrap admin gets a global-scope role binding.
  - `audit_events.actor_tenant` becomes nullable (migration) for anonymous denials.
  - `REDIS_QUEUE_RETRY_AFTER` invariant against per-supervisor timeouts.
  - Quadlet `StopTimeout` + `[Service] TimeoutStopSec` both set.
  - `BindTenantContext` also calls `Tenant::makeCurrent()`/`forgetCurrent()` for Spatie multitenancy.
  - `Horizon::auth()` (covers all environments) instead of `gate()` only.
  - Two-scan Grype model: full report (non-blocking SARIF) + fixed-only blocking gate.
  - `anchore/scan-action@v7.4.0` (Node 24) + `github/codeql-action/upload-sarif@v4`; `security-events: write` permission.
  - `.grype.yaml` at repo root (the location scan-action v7 expects).
  - Self-hosted runner script reads token via stdin/file, verifies the runner archive checksum.

## Why now

The MVP loop is otherwise closed. PROGRESS.md's "Next" tracks four items, three of which are stale on inspection:

1. **`laravel/horizon` Illuminate-13 compat** — already unblocked. Packagist publishes `laravel/horizon v5.47.1` with `illuminate/contracts ^9.21|^10.0|^11.0|^12.0|^13.0`. A `composer require --dry-run` resolves cleanly against the current lockfile.
2. **`enlightn/security-checker` Symfony-8 compat** — was never load-bearing. It scans `composer.lock` against the FriendsOfPHP advisory DB. `composer audit` (already in CI) uses the same database. Redundant tool, no longer worth pinning.
3. **GHA Node 20 deprecation** — already resolved. Existing workflows use `@v6`/`@v4` actions. Zero Node-20-era refs remain *in the current workflows*. The new Grype steps in this spec use `@v7`/`@v4` (Node 24).
4. **`fabpot/local-php-security-checker` (considered as a replacement)** — archived 2024-08, do not adopt.

This spec covers the substantive change (Horizon wire-up) and the supply-chain hardening that the audit exposed (Dependabot + image CVE scanning). The remaining MVP-closure items (real baseline-worker-host soak, podman-runtime-ci against a self-hosted runner) need external infra; this spec preps the runner-registration helpers as a sibling deliverable.

## Goals

- Install `laravel/horizon` and replace four legacy worker Quadlets with a **two-container** Horizon topology partitioned by Podman-socket exposure.
- Gate `/horizon` behind `AccessResolver::permittedPlatform()` with a new `horizon.view` permission and a global-scope role binding for the bootstrap admin. No raw Spatie role checks.
- Fix a latent pre-existing bug: workers listened on `provider`/`scripts`/`console`/`notifications` queues while jobs dispatched on `provider-worker`/`script-worker`/`console-worker`/`notification-worker`. Horizon's queue list aligns with the actual job dispatches.
- Ensure Redis `retry_after > timeout` invariant holds for every supervisor.
- Make `audit_events.actor_tenant` nullable so anonymous denials can be recorded.
- Plug the Spatie multitenancy leak in `BindTenantContext`.
- Add `.github/dependabot.yml`.
- Add Anchore Grype (`@v7`, Node 24) to `build-images.yml` with a two-scan model.
- Clean stale PROGRESS.md / `docs/prd/17` notes.
- Prep `scripts/dev/register-host-runner.sh` (stdin token, checksum verification) + systemd-user unit template.

## Non-goals

- Pulse / Telescope wire-up (→ M13b).
- Scale-profile (Nomad) Horizon topology (→ M12).
- Horizon-driven `racklab:ops-smoke` replacement (→ next slice, paired with the soak).
- Replacement of `composer audit`, Semgrep, Roave, or `racklab:security-check`. Grype + Dependabot are additive.
- Trivy. Anchore Syft is already in the pipeline; Grype is the natural pairing.
- Wrapping scheduler-reconciler artisan commands as Horizon Job classes (→ follow-up slice).

## Stack at a glance

| Slot | Pick | Version pin |
|------|------|-------------|
| Queue supervisor | `laravel/horizon` | `^5.47` (v5.47.1) |
| Horizon Redis connection | existing `redis` queue connection | unchanged shape; `REDIS_QUEUE_RETRY_AFTER` bumped to 3700+ |
| Auth gate | `App\Auth\HorizonAuthGate` invoked via `Horizon::auth()` | new |
| Platform-scope authorization | new method `App\Domain\Tenancy\AccessResolver::permittedPlatform()` | new |
| New permission | `horizon.view` | added to `DefaultRoleCatalog` for `admin` + `support` |
| Bootstrap admin RBAC | gains a global-scope role binding (in addition to project-scope) | new |
| Audit schema | `audit_events.actor_tenant` becomes nullable via new migration | new |
| Worker tenant leak | `BindTenantContext` extended to clear Spatie's `Tenant::current()` | new |
| Dependency-update bot | GitHub Dependabot | native |
| Image CVE scan | `anchore/scan-action@v7.4.0` + `.grype.yaml` | new |
| SARIF upload | `github/codeql-action/upload-sarif@v4` | new |
| Image SBOM (already installed) | `anchore/syft` v1.44.0 | unchanged |
| Runner registration | `actions/runner` v2 (downloaded; checksum-verified) | latest at runtime |

## Design

### 1. Horizon install

- `composer require laravel/horizon ^5.47`. Lockfile gains `laravel/horizon v5.47.1` and `laravel/sentinel v1.1.0`.
- `php artisan horizon:install` publishes `config/horizon.php`, `app/Providers/HorizonServiceProvider.php`, `public/vendor/horizon/`. We rewrite the provider; the published config is replaced with the topology in §2.
- `bootstrap/providers.php` gains `App\Providers\HorizonServiceProvider::class`.
- `composer.json` adds explicit `ext-pcntl` and `ext-posix` requires.

### 2. Supervisor topology

`config/horizon.php` declares **four** supervisors. **Queue names match the actual job dispatches** (verified in `app/Jobs/RunScriptContainer.php`, `app/Jobs/PollProxmoxTask.php`, `app/Jobs/RunFakeProviderTask.php`):

| Supervisor | Queues (in priority order) | Pool group | `balance` | `tries` | `timeout` |
|------------|----------------------------|------------|-----------|---------|-----------|
| `racklab-provider` | `provider-worker`, `provider`, `default` | `app` | `auto` | 1 | 300 s |
| `racklab-scripts` | `script-worker`, `scripts`, `cleanup` | `runner` | `auto` | 1 | 900 s |
| `racklab-console` | `console-worker`, `console` | `runner` | `simple` | 1 | 3600 s |
| `racklab-notifications` | `notification-worker`, `notifications`, `default` | `app` | `auto` | 3 | 120 s |

Each supervisor's queue list keeps the legacy aliases (`provider`, `scripts`, `console`, `notifications`, `default`) so any in-flight payloads dispatched on the old names continue to drain.

#### Console queue override (v3)

`app/Jobs/RunConsoleScript` extends `RunScriptContainer`. The parent's `__construct()` calls `$this->onQueue('script-worker')`. Without an override, console jobs land on the `script-worker` queue and are processed by `racklab-scripts` (timeout 900s) — but they may need up to 3600s. v3 overrides:

```php
// app/Jobs/RunConsoleScript.php
public function __construct(/* ...args from parent... */)
{
    parent::__construct(/* ...args... */);
    $this->onQueue('console-worker');
}
```

A regression test in `tests/Tiny/Jobs/JobQueueNamesTest.php` locks the per-class queue name. The legacy `console` alias in the `racklab-console` supervisor's queue list preserves drain compatibility for any in-flight `console`-queue payloads.

#### Pool group selection

`config/horizon.php` reads `RACKLAB_HORIZON_POOL_GROUP` (`app`, `runner`, or `all`) and emits only the supervisors that match. This is what lets us run **two Horizon containers**, each managing a subset:

- `RACKLAB_HORIZON_POOL_GROUP=app`: runs `racklab-provider`, `racklab-notifications`.
- `RACKLAB_HORIZON_POOL_GROUP=runner`: runs `racklab-scripts`, `racklab-console`.
- `RACKLAB_HORIZON_POOL_GROUP=all`: runs all four (used in local dev + Pest's testing env).

This partition is the v2 privilege-boundary fix (see §5).

#### Redis `retry_after` invariant — safe by default

Per Laravel docs: `timeout < retry_after` or jobs can be processed twice. The console supervisor's `timeout=3600s` clashes with Laravel's default `REDIS_QUEUE_RETRY_AFTER=90`. v2 changed `.env.example` only; v3 changes the **config default** in `config/queue.php` to 3700, so Baseline is safe regardless of whether `racklab.env` happens to set the var. `.env.example` keeps the explicit `REDIS_QUEUE_RETRY_AFTER=3700` for clarity.

`config/queue.php` change:

```php
'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 3700),
```

The Tiny invariant test iterates resolved per-env supervisors (not just `defaults`), covering all of `production`, `local`, `testing`:

```php
it('keeps every supervisor timeout < Redis retry_after across all envs', function (): void {
    $queue = require base_path('config/queue.php');
    $retryAfter = (int) $queue['connections']['redis']['retry_after'];

    foreach (['app', 'runner', 'all'] as $poolGroup) {
        putenv("RACKLAB_HORIZON_POOL_GROUP={$poolGroup}");
        $horizon = require base_path('config/horizon.php');
        putenv('RACKLAB_HORIZON_POOL_GROUP');

        foreach ($horizon['defaults'] as $name => $supervisor) {
            expect($supervisor['timeout'])->toBeLessThan(
                $retryAfter,
                "supervisor {$name} (group={$poolGroup}) timeout must be < retry_after",
            );
        }
    }
});
```

Three environments configured (`production`, `local`, `testing`). `testing` sets `processes=1` + `balance=simple` everywhere for deterministic Pest runs.

The existing `racklab-scheduler-reconciler@.container` continues unchanged (its `while true` shell loop). Wrapping its artisan commands as Job classes is the follow-up slice.

### 3. Auth gate

`/horizon` is a platform-wide resource, not tenant-scoped. v2 over-authorized: a global-scope binding on *any* resource would have granted Horizon. v3 introduces a dedicated platform resource so a global admin binding on a project does NOT carry over to platform features.

**Platform resource:** `(resource_type='platform', resource_id='racklab')`. Reserved. `App\Domain\Tenancy\PlatformResource` exposes its identity as constants:

```php
final class PlatformResource
{
    public const string RESOURCE_TYPE = 'platform';
    public const string RACKLAB_ID = 'racklab';
}
```

**New method on `AccessResolver`:**

```php
public function permittedPlatform(
    ActorIdentity $actor,
    Permission $permission,
): AccessDecision
```

Semantics: looks up all role bindings for `$actor`; **filters to bindings with `scope_type=global` AND `resource_type='platform'` AND `resource_id='racklab'`**; for each, asks `RolePermissionLookup::roleGrants()`; returns `AccessDecision::allowed()` if any pass, otherwise denied. No tenant predicate, no visibility predicate, no over-broad global-binding sweep.

**Bootstrap admin binding shape (v3):** `(principal=user/<id>, scope_type=global, resource_type='platform', resource_id='racklab', role='admin')`. Idempotent `firstOrCreate` keyed on those five columns.

`App\Auth\HorizonAuthGate::authorize(?User $user): bool`:
1. Anonymous (`null`) — emit `horizon.access.denied` audit row, return false.
2. Authenticated — call `permittedPlatform($actor, new Permission('horizon.view'))`.
3. On allow — emit `horizon.access` row, return true.
4. On deny — emit `horizon.access.denied` row, return false.

`HorizonServiceProvider::boot()` calls `Horizon::auth()` (all-env coverage).

**Anonymous flow (v3 accepts 403, not redirect).** Horizon's `web`-only middleware doesn't include `auth`. v2's contract test expected a `/login` redirect; this would have required adding `auth` to the middleware list, but then anonymous requests would never reach the gate and the `horizon.access.denied` audit row wouldn't be written for anonymous probes. v3 keeps middleware as `['web', BindAuthenticatedTenant::class]`, accepts that anonymous gets 403, and the gate-driven audit row makes anonymous probes visible.

### 4. Permission catalog

Add `horizon.view` to:
- `app/Domain/Rbac/DefaultRoleCatalog.php`: `admin` and `support` only.
- `tests/Snapshots/roles.json`: regenerated.

`Permission` is a thin readonly value object (`new Permission('horizon.view')`); no enum change.

### 5. Quadlet refactor — two-container split

The v1 single-container plan widened the privilege boundary by mounting the host Podman socket where `provider-worker` and `notification-worker` jobs run. v2 splits into two Quadlets matching `RACKLAB_HORIZON_POOL_GROUP`. v3 additionally tightens plugin volumes to read-only on both runtime containers (codex v2 P1) — only `racklab-plugin-bootstrap.container` retains write access for plugin install/migrate.

| Quadlet | Pool group | Podman socket | Plugin volume | Storage |
|---------|-----------|---------------|---------------|---------|
| `racklab-horizon-app.container` | `app` (provider + notifications) | **NO** | `:ro,Z` | rw |
| `racklab-horizon-runner.container` | `runner` (scripts + console) | mounted | `:ro,Z` | rw |

Both run `php artisan horizon`. Plugin code is read-only at runtime — any plugin install/migrate work goes through `racklab-plugin-bootstrap` which retains write access. This means `php artisan racklab:plugin install <slug>` invoked from a runtime container would be a no-op write to a read-only mount; the operator-facing path is to invoke it from the bootstrap container or to use the operator-facing artisan-host wrapper.

Quadlet timing keys:

```ini
StopSignal=SIGTERM
StopTimeout=3700

[Service]
TimeoutStopSec=3730
```

`StopTimeout=3700` (Quadlet/Podman key, integer seconds) is the Podman stop grace; `TimeoutStopSec=3730` (systemd key, in `[Service]`) is the outer systemd budget. Existing units already pair the two; v2 preserves that convention.

**Delete in this commit:** the four legacy worker Quadlets (`racklab-provider-worker@.container`, `racklab-script-worker@.container`, `racklab-console-worker@.container`, `racklab-notification-worker@.container`).

**Keep unchanged:** `racklab-scheduler-reconciler@.container`.

**Update** `racklab-runtime.target` `Wants=` line to include `racklab-horizon-app.service`, `racklab-horizon-runner.service`, and `racklab-scheduler-reconciler@1.service` (keeping that one as-is).

**Update** `scripts/baseline-install.sh` to render the new Quadlets, and to remove the four legacy units idempotently on upgrade.

**Container images:** Containerfile gains a single `horizon` target that both Quadlets pull. The build-images workflow's target matrix shrinks from seven to four (`web`, `reverb`, `horizon`, `scheduler-reconciler`). The legacy per-pool image tags continue to publish for one release cycle as identical mirror tags pointing at the same `horizon` image — implemented via an explicit `docker tag` + `docker push` step in the workflow (codex P2 fix: shrinking the matrix doesn't auto-tag legacy names without explicit logic).

### 6. Audit schema — nullable `actor_tenant`

`database/migrations/2026_05_27_000002_create_audit_events_table.php` declares `actor_tenant` as NOT NULL with FK constraint. Anonymous denial paths (e.g., un-authed `/horizon` visit hitting the gate before any tenant context) can't write a row in that schema.

v2 adds a new migration `database/migrations/2026_05_28_xxxxxx_make_audit_actor_tenant_nullable.php`:

```php
public function up(): void
{
    Schema::table('audit_events', function (Blueprint $t): void {
        $t->dropForeign(['actor_tenant']);
        $t->foreignUlid('actor_tenant')->nullable()->change();
        $t->foreign('actor_tenant')->references('id')->on('tenants')->restrictOnDelete();
    });
}

public function down(): void
{
    Schema::table('audit_events', function (Blueprint $t): void {
        // No-op on down: we don't want to make it NOT NULL again because rows
        // with NULL actor_tenant (anonymous denials) may now exist.
    });
}
```

`AuditEventWriter::append()` already accepts a nullable `$actorTenantId`; no code-side change required, only the schema relaxation. A snapshot test in `tests/Snapshots/AuditEventsTest.php` confirms `horizon.access` and `horizon.access.denied` are both present and both covered by contract tests.

### 7. BindTenantContext — Spatie tenant leak fix

`app/Jobs/Middleware/BindTenantContext.php` currently:

```php
$this->tenantContext->forget();
$this->tenantContext->set(new TenantContext(...));
// ...
$this->tenantContext->forget();
```

This clears RackLab's `TenantContextStore` but not Spatie's `Tenant::current()`. If Job A on tenant X runs, sets Spatie's current tenant, and Job B on tenant Y picks up the same Horizon worker, Job B's downstream Eloquent queries (via Spatie scopes) could see Tenant X's state until something else sets Spatie's tenant.

v2 extension:

```php
public function handle(TenantAwareJob $job, Closure $next): mixed
{
    $this->tenantContext->forget();
    Tenant::forgetCurrent(); // Spatie

    $tenant = Tenant::query()->findOrFail($job->tenantId());
    $this->tenantContext->set(new TenantContext(activeTenantId: $tenant->id));
    $tenant->makeCurrent(); // Spatie

    try {
        return $next($job);
    } finally {
        $this->tenantContext->forget();
        Tenant::forgetCurrent(); // Spatie
    }
}
```

Integration test `tests/Integration/TenantLeakBetweenJobsTest.php`: dispatches Job A on tenant X, Job B on tenant Y onto the same sync queue (which exercises the middleware), and asserts Spatie's `Tenant::current()` reflects Y inside Job B and is null between jobs.

### 8. Dependabot

`.github/dependabot.yml` (unchanged from v1):

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
    open-pull-requests-limit: 10
  - package-ecosystem: github-actions
    directory: /
    schedule: { interval: weekly, day: monday }
    commit-message: { prefix: "ci(deps)" }
    open-pull-requests-limit: 10
  - package-ecosystem: docker
    directory: /
    schedule: { interval: weekly, day: monday }
    commit-message: { prefix: "build(deps)" }
    open-pull-requests-limit: 10
```

Dependabot bot-PR commits are not Bitwarden-signed (codex P3); we accept this — the bot opens PRs, the maintainer rebases or merges, and the merge commit (if any) is signed by the maintainer. Documented in `docs/prd/17`.

### 9. Grype — two-scan model

The v1 single-scan with `only-fixed: true` hides unfixed CVEs from the SARIF entirely. v2 runs two scans per image:

**Scan A (full report, non-blocking, full visibility):**
```yaml
- name: Grype full report
  uses: anchore/scan-action@v7
  with:
    sbom: ${{ runner.temp }}/sbom-${{ matrix.target }}-cyclonedx.json
    fail-build: false
    severity-cutoff: low
    only-fixed: false
    output-format: sarif
    output-file: grype-${{ matrix.target }}-full.sarif
- name: Upload full SARIF
  if: always()
  continue-on-error: true  # fork PRs don't have security-events: write; tolerate upload failure
  uses: github/codeql-action/upload-sarif@v4
  with:
    sarif_file: grype-${{ matrix.target }}-full.sarif
    category: grype-${{ matrix.target }}-full
```

**Scan B (fixed-only, blocking gate):**
```yaml
- name: Grype fixed-CVE failure gate
  uses: anchore/scan-action@v7
  with:
    sbom: ${{ runner.temp }}/sbom-${{ matrix.target }}-cyclonedx.json
    fail-build: true
    severity-cutoff: high
    only-fixed: true
    config: .grype.yaml
    output-format: table
```

Workflow `permissions:` block gains `security-events: write` (required by `upload-sarif`).

`.grype.yaml` lives at repo root (the location scan-action v7 expects). Initial allowlist empty; documented exception pattern: CVE id + package + rationale + expiry date.

### 10. PROGRESS.md / PRD doc cleanup

- PROGRESS.md "Next" section: remove the Horizon-blocked claim from item #1, drop the Node-20 line from item #3, drop the enlightn paragraph; add a Horizon-shipped block to the body.
- `docs/prd/17-engineering-quality-typing-ci.md`: drop the enlightn paragraph; add a Grype line; note that Dependabot bot-PR commits aren't Bitwarden-signed.
- `CLAUDE.md` + `AGENTS.md`: stack-table Queue+jobs row updates to v5.47.1 with explicit `ext-pcntl`/`ext-posix`.

### 11. Self-hosted runner prep

- `scripts/dev/register-host-runner.sh`:
  - Accepts `--token-file=PATH` or reads from stdin. Refuses `--token=` (codex P3 — secret leaks into shell history).
  - Downloads `actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz`.
  - Downloads the matching `.tar.gz.sha256` and verifies the archive before extraction.
  - Refuses to overwrite an existing config without `--reconfigure`.
- `scripts/dev/racklab-self-hosted-runner.service.template`: systemd-user unit, `Restart=always`.
- Locked by `tests/Integration/SelfHostedRunnerScriptTest.php`: required labels, refusal-without-token, no `--token=` flag, checksum step present, refusal of overwrite without `--reconfigure`.

## Test plan

### Tiny

- `HorizonConfigShapeTest`: parses `config/horizon.php`, asserts the four supervisor defaults (queues, timeout, tries, balance), three envs, testing-env determinism.
- `HorizonRetryAfterInvariantTest`: every supervisor's `timeout` < `config/queue.php` Redis `retry_after`, iterated across `app`/`runner`/`all` pool groups.
- `HorizonPoolGroupSelectionTest`: with `RACKLAB_HORIZON_POOL_GROUP=app`, only `racklab-provider` + `racklab-notifications` appear; `=runner` → only scripts + console; `=all` → all four.
- `HorizonAuthGateTest`: anonymous denied; user with platform-scope `admin` binding allowed; user with global-scope binding on a *project* (not platform) denied; user with global platform-scope `student` binding denied (role lacks permission).
- `AccessResolverPlatformTest`: `permittedPlatform()` requires `scope_type=global` AND `resource_type='platform'` AND `resource_id='racklab'`; a global binding on `(project, X)` does NOT satisfy `permittedPlatform()`.
- `JobQueueNamesTest`: each Job class's `$this->queue` matches the supervisor's queue list. `RunConsoleScript` overrides to `console-worker`; `RunUserScript`/`RunAnsiblePlaybook` stay on `script-worker`; `PollProxmoxTask`/`RunFakeProviderTask` stay on `provider-worker`.
- `DependabotConfigTest`: parses `.github/dependabot.yml`, asserts the four ecosystems + conventional-commit prefixes.

### Contract

- `HorizonDashboardAccessTest`:
  - Anonymous → 403 (NOT a redirect, because Horizon's `web`-only middleware doesn't auth); `horizon.access.denied` audit row with `actor_tenant=null`.
  - Authenticated student (no platform binding) → 403; audit row.
  - Authenticated admin with platform-scope binding → 200; audit row.
  - Authenticated user with global-scope binding on a project (NOT platform) → 403; over-auth regression guard.
- `BootstrapAdminPlatformBindingTest`: `racklab:bootstrap-admin` creates a binding with `(scope_type=global, resource_type='platform', resource_id='racklab', role='admin')` for the bootstrap user (in addition to its existing project-scope binding). Idempotent — second run doesn't duplicate.
- `BaselineInstallScriptTest` extension: renders `racklab-horizon-app.container` + `racklab-horizon-runner.container`; the runner container has `Volume=/run/podman/podman.sock:...`; the app container does NOT; legacy four worker units removed on upgrade.
- `BuildImagesWorkflowTest` extension: Grype full + fixed-only scans present; both target `anchore/scan-action@v7`; `upload-sarif@v4` present; `permissions.security-events: write` declared; `.grype.yaml` at repo root.
- `SelfHostedRunnerScriptTest`: script shape (required labels, stdin/file token, checksum step, refuse-overwrite gating).
- `DependabotConfigurationTest`: shape + conventional-commit prefixes.

### Integration

- `HorizonWorkerSmokeTest`: skip-if-Redis-unavailable. Boots Horizon with `RACKLAB_HORIZON_POOL_GROUP=all` against testing-env config, dispatches a `RunScriptContainer` job on the `script-worker` queue against `FakeContainerRuntime`, asserts the `script_runs` row lands `succeeded` through Horizon's path.
- `TenantLeakBetweenJobsTest`: dispatch Job A on tenant X (sync queue), Job B on tenant Y, assert Spatie `Tenant::current()` reflects Y inside Job B, null between jobs.

### Snapshot

- `tests/Snapshots/RolePermissionsTest.php`: `roles.json` gains `horizon.view` on `admin` + `support`.
- `tests/Snapshots/AuditEventsTest.php`: `audit-events.json` gains `horizon.access` + `horizon.access.denied`.

### Browser

- `FilamentAdminWorkflowTest` extension: admin clicks a Horizon link from the Filament panel; lands on `/horizon`; axe-core passes.

## Rollout

1. Compose v3 spec (this file). Codex review on v3 before any code (one more round).
2. **Tiny tests first.** `HorizonConfigShapeTest`, `HorizonRetryAfterInvariantTest`, `HorizonPoolGroupSelectionTest`, `JobQueueNamesTest`, `DependabotConfigTest`. Red.
3. Install Horizon. Publish + customize `config/horizon.php` with the four supervisors + pool-group switch + queue names matching actual dispatches. Tiny config tests green.
4. Change `config/queue.php` Redis `retry_after` default from `90` to `3700`. Bump `.env.example` `REDIS_QUEUE_RETRY_AFTER=3700` for clarity. Retry-after invariant test green (across all three pool groups).
5. `app/Jobs/RunConsoleScript.php` constructor override: `$this->onQueue('console-worker')`. `JobQueueNamesTest` turns green.
6. `AccessResolverPlatformTest` (Tiny) red. Implement `AccessResolver::permittedPlatform()` with the platform-resource filter (`scope_type=global` + `resource_type='platform'` + `resource_id='racklab'`). Green.
7. Permission catalog update + roles snapshot update.
8. Migration: `actor_tenant` nullable. Run; verify schema.
9. `HorizonAuthGateTest` Tiny red. Implement `HorizonAuthGate`. Green.
10. Implement `HorizonServiceProvider` with `Horizon::auth()` + middleware (`['web', BindAuthenticatedTenant::class]`, NO `auth`). Provider registered in `bootstrap/providers.php`.
11. `HorizonDashboardAccessTest` contract red (4 cases including the global-on-project regression guard). Update `BootstrapAdmin` to create the platform-scope binding `(global, platform, racklab, admin)`. `BootstrapAdminPlatformBindingTest` contract red → green. Contract dashboard tests green (anonymous → 403, not redirect).
12. Audit-events snapshot updated.
13. `BindTenantContext` Spatie fix. `TenantLeakBetweenJobsTest` integration red → green.
14. Commit: **feat(queue): install + wire laravel/horizon v5.47.1**.
15. Quadlets: write `racklab-horizon-app.container` + `racklab-horizon-runner.container` with plugin volume `:ro,Z` on both. Delete four legacy worker units. Update target. `BaselineInstallScriptTest` updates (plus a `:ro,Z` assertion on the plugin volume). Installer cleans legacy units.
15. Commit: **chore(deploy): split Horizon onto app + runner Quadlets**.
16. Containerfile + build-images matrix collapse + legacy mirror-tag publish step. `BuildImagesWorkflowTest` extension.
17. Commit: **build: collapse Horizon worker targets in Containerfile + image matrix**.
18. `HorizonWorkerSmokeTest` integration (skip-if-no-Redis).
19. Browser test extension.
20. Commit: **test: cover Horizon worker smoke and admin /horizon navigation**.
21. `.github/dependabot.yml`. `DependabotConfigurationTest` integration.
22. Commit: **ci(deps): enable Dependabot for composer/npm/actions/docker**.
23. `.grype.yaml` at repo root. Grype two-scan in `build-images.yml`. `security-events: write` permission. Workflow test extension.
24. Commit: **ci(images): scan Syft SBOMs with Anchore Grype**.
25. `scripts/dev/register-host-runner.sh` + systemd-user template + `SelfHostedRunnerScriptTest`.
26. Commit: **chore(scripts): prep self-hosted Podman runner registration**.
27. Docs cleanup (PRD §17, PROGRESS.md, CLAUDE.md, AGENTS.md).
28. Commit: **docs: record Horizon install + drop enlightn / stale Node 20 notes**.
29. Codex review of the full branch (`codex review --uncommitted`). Fold P0/P1.
30. Final full quality gate: `composer validate`, `pint:test`, `larastan`, `rector:dry`, `security:racklab`, `openapi:check`, `audit`, `security:semgrep`, `pest:snapshots`, `i18n:missing`, `check-platform-reqs`, `npm audit --audit-level=high`, `npm run build`, `git diff --check`, `composer test`, `composer pest:browser`, `APP_URL=http://127.0.0.1:8000 npm run a11y`.

All commits signed via the Bitwarden SSH agent. No `--no-verify`, no `--no-gpg-sign`.

## Risk register

- **Bumping `REDIS_QUEUE_RETRY_AFTER` to 3700s** delays redelivery on truly-stuck jobs from 90s to ~1 hour. Mitigated by the reconciler's separate inspection (it can mark a stuck job via independent state). The alternative — keeping retry_after low — would cause double-processing of legitimate long-running console jobs. Accepted.
- **Global-scope admin binding for the bootstrap admin** broadens that user's reach. In a Baseline single-platform deploy, the bootstrap admin is necessarily a platform admin; the binding makes the trust explicit. Documented in `docs/prd/06` (auth/RBAC).
- **`actor_tenant` nullable** widens what the audit schema accepts. Mitigation: `AuditChainVerifier` is unchanged (still verifies hash chain regardless of null fields); contract tests cover both null and non-null actor_tenant paths.
- **Two Horizon containers** doubles the process count on a Baseline host. Each is single-container, low memory (defaults to `memory_limit=128`). On a small Baseline host the overhead is acceptable.
- **`anchore/scan-action@v7.4.0`** is the current latest; if it regresses, pin via `anchore/scan-action@v7` (major-only) keeps minor-update flow open. Dependabot will track it.
- **Bot-PR commits unsigned**: noted in `docs/prd/17`. Maintainer's merge commit re-signs.

## References

- Laravel Horizon docs: https://laravel.com/docs/horizon
- Laravel queue worker timeout/retry_after: https://laravel.com/docs/queues#job-expirations-and-timeouts
- Spatie multitenancy current-tenant API: https://spatie.be/docs/laravel-multitenancy
- Redesign spec §05/§07/§11/§17: `docs/superpowers/specs/2026-05-26-laravel-redesign.md`
- PRD §05 architecture, §17 engineering: `docs/prd/05-architecture.md`, `docs/prd/17-engineering-quality-typing-ci.md`
- Anchore Grype: https://github.com/anchore/grype
- Anchore scan-action v7.4.0: https://github.com/anchore/scan-action/releases/tag/v7.4.0
- GitHub Dependabot v2 schema: https://docs.github.com/en/code-security/dependabot/working-with-dependabot/dependabot-options-reference
- Codex review v1 findings: `/tmp/codex-horizon-spec.onPDmH.md` (P0: none; P1: 9; P2: 6; P3: 3 — all P1+P2 folded into v2).
