# Horizon install + supply-chain hardening — Implementation Plan (v3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install Laravel Horizon, gate `/horizon` via `AccessResolver::permittedPlatform()` targeting a dedicated `(platform, racklab)` resource, partition Horizon onto two containers (app + runner) to preserve the Podman-socket privilege boundary, fix the BindTenantContext Spatie leak, make `audit_events.actor_tenant` nullable for anonymous denials, change the Redis `retry_after` default to 3700 so it's safe-by-default, override `RunConsoleScript` to queue on `console-worker`, mount plugin volumes read-only in runtime containers, add Dependabot + a two-scan Anchore Grype, prep self-hosted Podman runner registration helpers, and clean stale PROGRESS.md/PRD notes — all under TDD.

**v3 vs prior:** v1 had 9 P1s; v2 had 5 more P1s; v3 folds all 14. See spec changelog. Key v3 deltas from v2 plan: platform-resource targeting in `permittedPlatform`, console queue override, config-default `retry_after`, RO plugin mount, anonymous-403 contract expectation.

**Architecture:**
- **Two Horizon containers** (`racklab-horizon-app.container` for `provider-worker`+`notification-worker` queues, no Podman socket; `racklab-horizon-runner.container` for `script-worker`+`console-worker` queues, with Podman socket).
- `config/horizon.php` partitions supervisors via `RACKLAB_HORIZON_POOL_GROUP` env var (`app` | `runner` | `all`).
- `App\Auth\HorizonAuthGate` gates `/horizon` via `Horizon::auth()` (covers all envs) calling `AccessResolver::permittedPlatform()` — new method that only considers `RoleBindingScopeType::Global` bindings.
- Bootstrap admin gets BOTH a project-scope binding (existing) AND a new global-scope `admin` binding.
- `BindTenantContext` extended to drive Spatie's `Tenant::makeCurrent()` / `Tenant::forgetCurrent()`.
- `audit_events.actor_tenant` migrated to nullable.

**Tech Stack:** PHP 8.3+, Laravel 13, `laravel/horizon` ^5.47, Spatie multitenancy v4.1.3 (`IsTenant::makeCurrent()`/`forgetCurrent()` verified), Pest 4, Larastan max, Anchore Syft v1.44.0 + Anchore Grype via `anchore/scan-action@v7`, `github/codeql-action/upload-sarif@v4`, GitHub Dependabot v2, `actions/runner` v2.

---

## File Structure

**New files:**
- `app/Auth/HorizonAuthGate.php`
- `app/Providers/HorizonServiceProvider.php` (replaces published stub)
- `app/Domain/Tenancy/PlatformResource.php` (sentinel TenantScopedResource for clarity even though `permittedPlatform()` doesn't read it)
- `config/horizon.php` (custom shape, replaces published stub)
- `database/migrations/2026_05_28_000001_make_audit_actor_tenant_nullable.php`
- `deploy/quadlets/racklab-horizon-app.container`
- `deploy/quadlets/racklab-horizon-runner.container`
- `.github/dependabot.yml`
- `.grype.yaml` (repo root, NOT `.github/grype.yaml` — scan-action v7 expects root)
- `scripts/dev/register-host-runner.sh`
- `scripts/dev/racklab-self-hosted-runner.service.template`
- Tests: `tests/Tiny/Horizon/HorizonConfigShapeTest.php`, `tests/Tiny/Horizon/HorizonRetryAfterInvariantTest.php`, `tests/Tiny/Horizon/HorizonPoolGroupSelectionTest.php`, `tests/Tiny/Horizon/HorizonAuthGateTest.php`, `tests/Tiny/Tenancy/AccessResolverPlatformTest.php`, `tests/Tiny/Dependabot/DependabotConfigTest.php`, `tests/Contract/Horizon/HorizonDashboardAccessTest.php`, `tests/Contract/Identity/BootstrapAdminGlobalBindingTest.php`, `tests/Integration/HorizonWorkerSmokeTest.php`, `tests/Integration/TenantLeakBetweenJobsTest.php`, `tests/Integration/SelfHostedRunnerScriptTest.php`, `tests/Integration/DependabotConfigurationTest.php`.

**Modified files:**
- `composer.json` — add `laravel/horizon` ^5.47, `ext-pcntl`, `ext-posix`.
- `composer.lock` — regenerated.
- `.env.example` — bump `REDIS_QUEUE_RETRY_AFTER` to 3700.
- `bootstrap/providers.php` — register `HorizonServiceProvider`.
- `app/Domain/Tenancy/AccessResolver.php` — add `permittedPlatform()` method.
- `app/Domain/Rbac/DefaultRoleCatalog.php` — add `horizon.view` to admin + support.
- `app/Console/Commands/BootstrapAdmin.php` — create global-scope admin binding (in addition to existing project-scope).
- `app/Jobs/Middleware/BindTenantContext.php` — drive Spatie's `Tenant::makeCurrent()`/`forgetCurrent()`.
- `tests/Snapshots/roles.json` — add `horizon.view`.
- `tests/Snapshots/audit-events.json` — add `horizon.access`, `horizon.access.denied`.
- `deploy/quadlets/racklab-runtime.target` — Wants= line.
- `scripts/baseline-install.sh` — render the two new Quadlets + remove four legacy units idempotently.
- `Containerfile` — collapse 4 worker targets into single `horizon` target.
- `.github/workflows/build-images.yml` — collapse matrix, add legacy mirror tags, add two-scan Grype, add `security-events: write` permission.
- `tests/Integration/BaselineInstallScriptTest.php`, `tests/Integration/BuildImagesWorkflowTest.php`, `tests/Browser/FilamentAdminWorkflowTest.php` — extended.
- `docs/prd/17-engineering-quality-typing-ci.md`, `PROGRESS.md`, `CLAUDE.md`, `AGENTS.md` — doc cleanup.

**Deleted files:** four legacy worker Quadlets (`racklab-provider-worker@.container`, `racklab-script-worker@.container`, `racklab-console-worker@.container`, `racklab-notification-worker@.container`).

---

## Task 1: Install Horizon + bump REDIS_QUEUE_RETRY_AFTER

- [ ] **Step 1: Install via Composer**

```bash
composer require laravel/horizon:^5.47
composer require ext-pcntl:'*' ext-posix:'*'
```

Expected: `Installing laravel/horizon (v5.47.1)` + `Installing laravel/sentinel (v1.1.0)`.

- [ ] **Step 2: Change `config/queue.php` Redis `retry_after` default + bump `.env.example`**

In `config/queue.php`, find the `redis` connection block (around line 73). Change:

```php
'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
```

to:

```php
'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 3700),
```

This makes the default safe regardless of whether `racklab.env` sets the variable — the Baseline installer never rendered the env var, so v2's `.env.example`-only change was insufficient.

Also bump `.env.example` for clarity:

```env
REDIS_QUEUE_RETRY_AFTER=3700
```

Set the value in `.env` too for local dev consistency.

- [ ] **Step 3: Verify install + run baseline gates**

```bash
php artisan list | grep horizon | head -5
composer larastan && composer rector:dry && composer pint:test && composer pest:tiny
```

Expected: horizon commands listed; all four gates green. No tests added yet.

- [ ] **Step 4: Stage but don't commit** — bundled with the config + gate work in Task 12.

---

## Task 2: HorizonConfigShape + RetryAfterInvariant + PoolGroupSelection Tiny tests

**Files:**
- Create: `tests/Tiny/Horizon/HorizonConfigShapeTest.php`
- Create: `tests/Tiny/Horizon/HorizonRetryAfterInvariantTest.php`
- Create: `tests/Tiny/Horizon/HorizonPoolGroupSelectionTest.php`

- [ ] **Step 0: Add `JobQueueNamesTest` to the Tiny test set**

Create `tests/Tiny/Jobs/JobQueueNamesTest.php` that locks each Job class's queue name against the supervisor queue lists:

```php
<?php

declare(strict_types=1);

namespace Tests\Tiny\Jobs;

use function PHPUnit\Framework\assertSame;

it('RunUserScript queues on script-worker', function (): void {
    $job = new \App\Jobs\RunUserScript(/* minimal-args fixture */);
    assertSame('script-worker', $job->queue);
});

it('RunAnsiblePlaybook queues on script-worker', function (): void {
    $job = new \App\Jobs\RunAnsiblePlaybook(/* minimal-args fixture */);
    assertSame('script-worker', $job->queue);
});

it('RunConsoleScript queues on console-worker (overrides parent script-worker)', function (): void {
    $job = new \App\Jobs\RunConsoleScript(/* minimal-args fixture */);
    assertSame('console-worker', $job->queue);
});

it('PollProxmoxTask queues on provider-worker', function (): void {
    $job = new \App\Jobs\PollProxmoxTask(/* minimal-args fixture */);
    assertSame('provider-worker', $job->queue);
});

it('RunFakeProviderTask queues on provider-worker', function (): void {
    $job = new \App\Jobs\RunFakeProviderTask(/* minimal-args fixture */);
    assertSame('provider-worker', $job->queue);
});
```

> **Note:** Each Job class's constructor signature varies. Read each before authoring the test fixture; pass the minimum required arguments. The test reads `$job->queue` (Laravel's standard queue-name property set by `onQueue()`).

Run; expect FAIL on `RunConsoleScript` (parent sets `script-worker`).

After this step, add the override in `app/Jobs/RunConsoleScript.php`. **Important (codex v3 P1):** Laravel's job-level `$timeout` takes precedence over the supervisor's `timeout` config. `RunScriptContainer` declares `public int $timeout = 330`, which would kill console jobs at 5.5 minutes regardless of Horizon's 3600s supervisor timeout. v3 also overrides `$timeout`:

```php
final class RunConsoleScript extends RunScriptContainer
{
    /** Override parent's 330 — console jobs may need up to 1 hour. */
    public int $timeout = 3630;  // 3600s console run + 30s cleanup margin; still < retry_after=3700

    public function __construct(/* same args as parent */)
    {
        parent::__construct(/* forward args */);
        $this->onQueue('console-worker');
    }
}
```

Add a Tiny test in `JobQueueNamesTest` that asserts the timeout invariant:

```php
it('RunConsoleScript has timeout 3630s sized for console runs', function (): void {
    $job = new \App\Jobs\RunConsoleScript(/* minimal args */);
    assertSame(3630, $job->timeout);
});

it('all script-running jobs have timeout < Redis retry_after', function (): void {
    $queue = require base_path('config/queue.php');
    $retryAfter = (int) $queue['connections']['redis']['retry_after'];

    foreach ([\App\Jobs\RunUserScript::class, \App\Jobs\RunAnsiblePlaybook::class, \App\Jobs\RunConsoleScript::class] as $class) {
        $job = new $class(/* minimal args */);
        expect($job->timeout)->toBeLessThan($retryAfter, $class);
    }
});
```

Also check `ContainerManifest::timeoutSeconds`. If the runner builds a manifest that caps the container at 300s, the console job still dies even with the job-level timeout fixed. Examine `app/Runtime/ContainerManifest.php`; if needed, override the manifest's timeoutSeconds for console-runner kind.

Re-run; expect all 5+ Job classes to assert correctly with the timeout invariants.

- [ ] **Step 1: Write `HorizonConfigShapeTest`**

```php
<?php

declare(strict_types=1);

namespace Tests\Tiny\Horizon;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

it('declares four RackLab supervisors with queue names matching the actual job dispatches', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    $defaults = $config['defaults'];

    // Queue names match RunScriptContainer::onQueue('script-worker'), PollProxmoxTask::onQueue('provider-worker'),
    // RunFakeProviderTask::onQueue('provider-worker'). Legacy aliases retained for drain compatibility.
    assertSame(['provider-worker', 'provider', 'default'], $defaults['racklab-provider']['queue']);
    assertSame(['script-worker', 'scripts', 'cleanup'], $defaults['racklab-scripts']['queue']);
    assertSame(['console-worker', 'console'], $defaults['racklab-console']['queue']);
    assertSame(['notification-worker', 'notifications', 'default'], $defaults['racklab-notifications']['queue']);

    assertSame(300, $defaults['racklab-provider']['timeout']);
    assertSame(900, $defaults['racklab-scripts']['timeout']);
    assertSame(3600, $defaults['racklab-console']['timeout']);
    assertSame(120, $defaults['racklab-notifications']['timeout']);

    assertSame(1, $defaults['racklab-provider']['tries']);
    assertSame(3, $defaults['racklab-notifications']['tries']);

    assertSame('redis', $defaults['racklab-provider']['connection']);
});

it('declares production, local, and testing environments', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    foreach (['production', 'local', 'testing'] as $env) {
        assertTrue(isset($config['environments'][$env]), "missing horizon environment: {$env}");
    }
});
```

- [ ] **Step 2: Write `HorizonRetryAfterInvariantTest`**

```php
<?php

declare(strict_types=1);

namespace Tests\Tiny\Horizon;

use function PHPUnit\Framework\assertLessThan;

it('keeps every Horizon supervisor timeout strictly less than the Redis retry_after', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $horizon = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    putenv('REDIS_QUEUE_RETRY_AFTER=3700');
    $queue = require base_path('config/queue.php');
    $retryAfter = (int) $queue['connections']['redis']['retry_after'];
    putenv('REDIS_QUEUE_RETRY_AFTER');

    foreach ($horizon['defaults'] as $name => $supervisor) {
        assertLessThan(
            $retryAfter,
            $supervisor['timeout'],
            "supervisor {$name} timeout {$supervisor['timeout']}s must be < retry_after {$retryAfter}s",
        );
    }
});
```

- [ ] **Step 3: Write `HorizonPoolGroupSelectionTest`**

```php
<?php

declare(strict_types=1);

namespace Tests\Tiny\Horizon;

use function PHPUnit\Framework\assertEqualsCanonicalizing;

it('emits only app supervisors when RACKLAB_HORIZON_POOL_GROUP=app', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=app');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    foreach (['production', 'local', 'testing'] as $env) {
        assertEqualsCanonicalizing(
            ['racklab-provider', 'racklab-notifications'],
            array_keys($config['environments'][$env]),
            "env {$env} should expose only app supervisors",
        );
    }
});

it('emits only runner supervisors when RACKLAB_HORIZON_POOL_GROUP=runner', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=runner');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    foreach (['production', 'local', 'testing'] as $env) {
        assertEqualsCanonicalizing(
            ['racklab-scripts', 'racklab-console'],
            array_keys($config['environments'][$env]),
            "env {$env} should expose only runner supervisors",
        );
    }
});

it('emits all four supervisors when RACKLAB_HORIZON_POOL_GROUP=all', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $config = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    foreach (['production', 'local', 'testing'] as $env) {
        assertEqualsCanonicalizing(
            ['racklab-provider', 'racklab-scripts', 'racklab-console', 'racklab-notifications'],
            array_keys($config['environments'][$env]),
        );
    }
});
```

- [ ] **Step 4: Run all three Tiny tests, confirm FAIL** (config/horizon.php doesn't exist yet)

```bash
vendor/bin/pest tests/Tiny/Horizon/ -v
```

Expected: all fail with "file not found" / "key not defined".

- [ ] **Step 5: Author `config/horizon.php`**

`php artisan horizon:install` first publishes the stub + assets; then replace `config/horizon.php` with:

```php
<?php

declare(strict_types=1);

$appSupervisors = ['racklab-provider', 'racklab-notifications'];
$runnerSupervisors = ['racklab-scripts', 'racklab-console'];
$poolGroup = env('RACKLAB_HORIZON_POOL_GROUP', 'all');

$selectSupervisors = static function (array $supervisorMap) use ($poolGroup, $appSupervisors, $runnerSupervisors): array {
    return match ($poolGroup) {
        'app' => array_intersect_key($supervisorMap, array_flip($appSupervisors)),
        'runner' => array_intersect_key($supervisorMap, array_flip($runnerSupervisors)),
        default => $supervisorMap,
    };
};

$productionMap = [
    'racklab-provider' => ['minProcesses' => 1, 'maxProcesses' => 6],
    'racklab-scripts' => ['minProcesses' => 1, 'maxProcesses' => 8],
    'racklab-console' => ['minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-notifications' => ['minProcesses' => 1, 'maxProcesses' => 4],
];

$localMap = [
    'racklab-provider' => ['minProcesses' => 1, 'maxProcesses' => 2],
    'racklab-scripts' => ['minProcesses' => 1, 'maxProcesses' => 2],
    'racklab-console' => ['minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-notifications' => ['minProcesses' => 1, 'maxProcesses' => 1],
];

$testingMap = [
    'racklab-provider' => ['balance' => 'simple', 'processes' => 1, 'minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-scripts' => ['balance' => 'simple', 'processes' => 1, 'minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-console' => ['balance' => 'simple', 'processes' => 1, 'minProcesses' => 1, 'maxProcesses' => 1],
    'racklab-notifications' => ['balance' => 'simple', 'processes' => 1, 'minProcesses' => 1, 'maxProcesses' => 1],
];

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', 'racklab_horizon:'),
    'middleware' => ['web', \App\Http\Middleware\BindAuthenticatedTenant::class],
    'waits' => [
        'redis:provider-worker' => 60,
        'redis:script-worker' => 60,
        'redis:console-worker' => 600,
        'redis:notification-worker' => 30,
    ],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [],
    'metrics' => [
        'trim_snapshots' => ['job' => 24, 'queue' => 24],
    ],
    'fast_termination' => false,
    'memory_limit' => 128,
    'defaults' => [
        'racklab-provider' => [
            'connection' => 'redis',
            'queue' => ['provider-worker', 'provider', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 6, 'maxTime' => 3600, 'maxJobs' => 0,
            'memory' => 128, 'tries' => 1, 'timeout' => 300, 'nice' => 0,
        ],
        'racklab-scripts' => [
            'connection' => 'redis',
            'queue' => ['script-worker', 'scripts', 'cleanup'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 8, 'maxTime' => 3600, 'maxJobs' => 0,
            'memory' => 128, 'tries' => 1, 'timeout' => 900, 'nice' => 0,
        ],
        'racklab-console' => [
            'connection' => 'redis',
            'queue' => ['console-worker', 'console'],
            'balance' => 'simple',
            'maxProcesses' => 1, 'maxTime' => 3600, 'maxJobs' => 0,
            'memory' => 128, 'tries' => 1, 'timeout' => 3600, 'nice' => 0,
        ],
        'racklab-notifications' => [
            'connection' => 'redis',
            'queue' => ['notification-worker', 'notifications', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4, 'maxTime' => 3600, 'maxJobs' => 0,
            'memory' => 128, 'tries' => 3, 'timeout' => 120, 'nice' => 0,
        ],
    ],
    'environments' => [
        'production' => $selectSupervisors($productionMap),
        'local' => $selectSupervisors($localMap),
        'testing' => $selectSupervisors($testingMap),
    ],
];
```

- [ ] **Step 6: Run, confirm green**

```bash
vendor/bin/pest tests/Tiny/Horizon/ -v
```

Expected: all green.

---

## Task 3: AccessResolver::permittedPlatform() + Tiny test (v3 platform-resource targeting)

**Files:**
- Create: `app/Domain/Tenancy/PlatformResource.php` (constant-only marker, NOT a TenantScopedResource).
- Create: `tests/Tiny/Tenancy/AccessResolverPlatformTest.php`
- Modify: `app/Domain/Tenancy/AccessResolver.php`

- [ ] **Step 1: Write the failing test (4 cases including over-auth regression guard)**

```php
<?php

declare(strict_types=1);

namespace Tests\Tiny\Tenancy;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingRecord;
use App\Domain\Tenancy\RoleBindingScopeType;

it('allows access when actor has a platform-scope binding granting the permission', function (): void {
    $actor = new ActorIdentity(id: 'user-1', tenantIds: ['tenant-a']);
    $bindings = [
        new RoleBindingRecord(
            principalType: 'user', principalId: 'user-1',
            role: 'admin',
            scopeType: RoleBindingScopeType::Global,
            tenantId: null, tenantSet: [],
            resourceType: 'platform', resourceId: 'racklab',
        ),
    ];
    $resolver = makeResolverWith(bindings: $bindings, adminPermissions: ['horizon.view']);

    expect($resolver->permittedPlatform($actor, new Permission('horizon.view'))->allowed)->toBeTrue();
});

it('denies access when actor has only tenant-local bindings', function (): void {
    $actor = new ActorIdentity(id: 'user-1', tenantIds: ['tenant-a']);
    $bindings = [
        new RoleBindingRecord(
            principalType: 'user', principalId: 'user-1',
            role: 'admin',
            scopeType: RoleBindingScopeType::TenantLocal,
            tenantId: 'tenant-a', tenantSet: [],
            resourceType: 'project', resourceId: 'project-1',
        ),
    ];
    $resolver = makeResolverWith(bindings: $bindings, adminPermissions: ['horizon.view']);

    expect($resolver->permittedPlatform($actor, new Permission('horizon.view'))->allowed)->toBeFalse();
});

it('OVER-AUTH REGRESSION GUARD: denies when actor has global-scope binding on a project (not the platform resource)', function (): void {
    $actor = new ActorIdentity(id: 'user-1', tenantIds: ['tenant-a']);
    $bindings = [
        new RoleBindingRecord(
            principalType: 'user', principalId: 'user-1',
            role: 'admin',
            scopeType: RoleBindingScopeType::Global,
            tenantId: null, tenantSet: [],
            resourceType: 'project',   // wrong resource type for platform
            resourceId: 'project-1',
        ),
    ];
    $resolver = makeResolverWith(bindings: $bindings, adminPermissions: ['horizon.view']);

    expect($resolver->permittedPlatform($actor, new Permission('horizon.view'))->allowed)->toBeFalse();
});

it('denies access when actor has platform-scope binding but role lacks the permission', function (): void {
    $actor = new ActorIdentity(id: 'user-1', tenantIds: ['tenant-a']);
    $bindings = [
        new RoleBindingRecord(
            principalType: 'user', principalId: 'user-1',
            role: 'student',
            scopeType: RoleBindingScopeType::Global,
            tenantId: null, tenantSet: [],
            resourceType: 'platform', resourceId: 'racklab',
        ),
    ];
    $resolver = makeResolverWith(bindings: $bindings, studentPermissions: []);

    expect($resolver->permittedPlatform($actor, new Permission('horizon.view'))->allowed)->toBeFalse();
});
```

The helper `makeResolverWith()` is a Pest test helper that wraps an in-memory RoleBindingRepository whose `forActor()` method (NEW — see Step 2) returns all bindings for the actor regardless of resource.

- [ ] **Step 2: Add `RoleBindingRepository::forActor()` interface method + implementation**

Edit `app/Domain/Tenancy/RoleBindingRepository.php`:

```php
public function forActor(ActorIdentity $actor): array;
```

Edit `app/Tenancy/EloquentRoleBindingRepository.php` to implement it:

```php
public function forActor(ActorIdentity $actor): array
{
    $records = [];

    foreach (RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', $actor->id)
        ->get() as $binding
    ) {
        $records[] = $this->toRecord($binding);
    }

    return $records;
}
```

Match the existing `forActorAndResource()` implementation's style (read the surrounding code first).

- [ ] **Step 3: Create `app/Domain/Tenancy/PlatformResource.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

final class PlatformResource
{
    public const string RESOURCE_TYPE = 'platform';
    public const string RACKLAB_ID = 'racklab';
}
```

- [ ] **Step 4: Add `AccessResolver::permittedPlatform()`** (v3: tightened to platform resource target)

Insert into `app/Domain/Tenancy/AccessResolver.php` after `permitted()`:

```php
public function permittedPlatform(
    ActorIdentity $actor,
    Permission $permission,
): AccessDecision {
    $bindings = $this->roleBindings->forActor($actor);
    $platformBindings = array_values(array_filter(
        $bindings,
        static fn (RoleBindingRecord $binding): bool =>
            $binding->scopeType === RoleBindingScopeType::Global
            && $binding->resourceType === PlatformResource::RESOURCE_TYPE
            && $binding->resourceId === PlatformResource::RACKLAB_ID,
    ));

    if ($platformBindings === []) {
        return new AccessDecision(
            allowed: false,
            denyReason: AccessDenyReason::InsufficientScope,
            provenance: [],
        );
    }

    $grantingBindings = array_values(array_filter(
        $platformBindings,
        fn (RoleBindingRecord $binding): bool => $this->rolePermissions->roleGrants($binding->role, $permission),
    ));

    if ($grantingBindings === []) {
        return new AccessDecision(
            allowed: false,
            denyReason: AccessDenyReason::PermissionNotGranted,
            provenance: [],
        );
    }

    return new AccessDecision(
        allowed: true,
        denyReason: null,
        provenance: array_map(
            static fn (RoleBindingRecord $b): string => "platform:racklab:role={$b->role}",
            $grantingBindings,
        ),
    );
}
```

- [ ] **Step 4: Run test, confirm green**

```bash
vendor/bin/pest tests/Tiny/Tenancy/AccessResolverPlatformTest.php
```

Expected: 3 passed.

- [ ] **Step 5: Run full Tiny gate**

```bash
composer pest:tiny && composer larastan
```

Expected: green. Larastan must be green — `permittedPlatform()` is the only AccessResolver-bypass surface for platform resources, and `NoSpatieBypassRule` still permits AccessResolver internals.

---

## Task 4: Permission catalog + roles snapshot

**Files:**
- Modify: `app/Domain/Rbac/DefaultRoleCatalog.php`
- Modify: `tests/Snapshots/roles.json`

- [ ] **Step 1: Edit `DefaultRoleCatalog`**

Add `'horizon.view',` in alphabetical order to both the `admin` and `support` permission arrays. Position: between `deployment.update` and `network.allocate_public_ip` for admin; same relative position for support.

- [ ] **Step 2: Run snapshot test, see the failure**

```bash
vendor/bin/pest tests/Snapshots/RolePermissionsTest.php
```

Expected: FAIL — catalog has `horizon.view` but snapshot doesn't.

- [ ] **Step 3: Update `tests/Snapshots/roles.json`**

Read the JSON, add `"horizon.view"` in matching alphabetical position for admin + support. Don't add to instructor/ta/student.

- [ ] **Step 4: Re-run snapshot test, confirm green**

```bash
vendor/bin/pest tests/Snapshots/RolePermissionsTest.php
```

Expected: green.

---

## Task 5: Migration — nullable `actor_tenant`

**Files:**
- Create: `database/migrations/2026_05_28_000001_make_audit_actor_tenant_nullable.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropForeign(['actor_tenant']);
            $table->foreignUlid('actor_tenant')->nullable()->change();
            $table->foreign('actor_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // No-op: rows with NULL actor_tenant may exist after this migration ran;
        // re-applying NOT NULL would fail. Audit retention prevents safe rollback.
    }
};
```

- [ ] **Step 2: Run migration locally**

```bash
php artisan migrate --force
```

Expected: migration runs without error. Verify with:

```bash
php artisan db:show 2>&1 | grep -A2 audit_events | head -5
```

The `down()` no-op is deliberate; document in the file's docblock too.

- [ ] **Step 3: Run pest:tiny to confirm migration didn't break existing audit tests**

```bash
composer pest:tiny
composer pest:contract --filter="Audit"
```

Expected: green.

---

## Task 6: HorizonAuthGate Tiny test + impl

**Files:**
- Create: `tests/Tiny/Horizon/HorizonAuthGateTest.php`
- Create: `app/Auth/HorizonAuthGate.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Tiny\Horizon;

use App\Auth\HorizonAuthGate;
use App\Domain\Audit\Fakes\InMemoryAuditEventWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessDecision;
use App\Domain\Tenancy\AccessDenyReason;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\Fakes\InMemoryRoleBindingRepository;
// ... etc

it('denies anonymous callers and emits horizon.access.denied with null actor_tenant', function (): void {
    $audit = new InMemoryAuditEventWriter();
    $gate = makeGate(audit: $audit, bindings: []);

    expect($gate->authorize(null))->toBeFalse();
    $events = $audit->byType('horizon.access.denied');
    expect($events)->toHaveCount(1);
    expect($events[0]->actorTenantId)->toBeNull();
});

it('allows a user with global-scope admin binding holding horizon.view', function (): void {
    $audit = new InMemoryAuditEventWriter();
    $user = makeUser('user-1');
    $gate = makeGate(
        audit: $audit,
        bindings: [globalAdminBinding('user-1')],
        adminPermissions: ['horizon.view'],
    );

    expect($gate->authorize($user))->toBeTrue();
    expect($audit->byType('horizon.access'))->toHaveCount(1);
});

it('denies a user without global binding even if their tenant-local binding has horizon.view', function (): void {
    $audit = new InMemoryAuditEventWriter();
    $user = makeUser('user-1');
    $gate = makeGate(
        audit: $audit,
        bindings: [tenantLocalAdminBinding('user-1', 'tenant-a')],
        adminPermissions: ['horizon.view'],
    );

    expect($gate->authorize($user))->toBeFalse();
    expect($audit->byType('horizon.access.denied'))->toHaveCount(1);
});

it('denies a user whose global binding role lacks horizon.view', function (): void {
    $audit = new InMemoryAuditEventWriter();
    $user = makeUser('user-1');
    $gate = makeGate(
        audit: $audit,
        bindings: [globalStudentBinding('user-1')],
        studentPermissions: [], // no horizon.view
    );

    expect($gate->authorize($user))->toBeFalse();
    expect($audit->byType('horizon.access.denied'))->toHaveCount(1);
});
```

`makeGate()`, `globalAdminBinding()`, etc. are Pest helpers — author them next to the test file as needed. Match the existing `InMemoryAuditEventWriter` API discovered in Task 3.

- [ ] **Step 2: Run, confirm fail**

```bash
vendor/bin/pest tests/Tiny/Horizon/HorizonAuthGateTest.php
```

Expected: FAIL — `App\Auth\HorizonAuthGate` not found.

- [ ] **Step 3: Implement `app/Auth/HorizonAuthGate.php`**

```php
<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Audit\AuditEventWriter;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Models\User;

final readonly class HorizonAuthGate
{
    public function __construct(
        private AccessResolver $resolver,
        private AuditEventWriter $audit,
    ) {}

    public function authorize(?User $user): bool
    {
        if ($user === null) {
            $this->emitDenied(null, 'anonymous');

            return false;
        }

        $actor = ActorIdentity::fromUser($user);
        $decision = $this->resolver->permittedPlatform($actor, new Permission('horizon.view'));

        if (! $decision->allowed) {
            $this->emitDenied($user, $decision->denyReason?->value ?? 'unknown');

            return false;
        }

        $this->emitAllowed($user);

        return true;
    }

    private function emitAllowed(User $user): void
    {
        $this->audit->append(
            type: 'horizon.access',
            actorTenantId: null,
            resourceTenantId: null,
            actorId: $user->id,
            metadata: ['user_id' => $user->id],
        );
    }

    private function emitDenied(?User $user, string $reason): void
    {
        $this->audit->append(
            type: 'horizon.access.denied',
            actorTenantId: null,
            resourceTenantId: null,
            actorId: $user?->id,
            metadata: ['reason' => $reason],
        );
    }
}
```

> **Note:** `ActorIdentity::fromUser()` may not exist yet — check `app/Domain/Tenancy/ActorIdentity.php`. If not present, add a static constructor that wraps a `User` model into `ActorIdentity(id: $user->id, tenantIds: $user->tenants->pluck('id')->all())`.

- [ ] **Step 4: Run test, confirm green**

```bash
vendor/bin/pest tests/Tiny/Horizon/HorizonAuthGateTest.php
```

Expected: green.

---

## Task 7: BootstrapAdmin platform-resource binding + Contract test (v3 tightening)

**Files:**
- Modify: `app/Console/Commands/BootstrapAdmin.php`
- Create: `tests/Contract/Identity/BootstrapAdminPlatformBindingTest.php`

**v3 binding shape:** `(scope_type=global, resource_type='platform', resource_id='racklab', role='admin')` — NOT a generic global binding (which would over-authorize per the codex v2 finding).

- [ ] **Step 1: Read the existing BootstrapAdmin command + understand its idempotency pattern**

```bash
cat app/Console/Commands/BootstrapAdmin.php
```

Identify where it creates the project-scope `RoleBinding`. The global binding should follow the same idempotent (firstOrCreate) pattern.

- [ ] **Step 2: Write the failing contract test**

```php
<?php

declare(strict_types=1);

namespace Tests\Contract\Identity;

use App\Models\RoleBinding;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

it('creates BOTH project-scope and platform-scope admin bindings for the bootstrap admin', function (): void {
    $this->artisan('racklab:bootstrap-admin', [
        '--email' => 'admin@example.test',
        '--password' => 'examplepass',
    ])->assertExitCode(0);

    $user = User::where('email', 'admin@example.test')->firstOrFail();

    expect(RoleBinding::query()
        ->where('principal_id', $user->id)
        ->where('scope_type', 'tenant_local')
        ->where('role', 'admin')
        ->where('resource_type', 'project')
        ->count())->toBeGreaterThanOrEqual(1);

    expect(RoleBinding::query()
        ->where('principal_id', $user->id)
        ->where('scope_type', 'global')
        ->where('role', 'admin')
        ->where('resource_type', 'platform')
        ->where('resource_id', 'racklab')
        ->count())->toBe(1);
});

it('is idempotent — re-running does not duplicate the platform binding', function (): void {
    $this->artisan('racklab:bootstrap-admin', [
        '--email' => 'admin@example.test',
        '--password' => 'examplepass',
    ])->assertExitCode(0);

    $this->artisan('racklab:bootstrap-admin', [
        '--email' => 'admin@example.test',
        '--password' => 'examplepass',
    ])->assertExitCode(0);

    $user = User::where('email', 'admin@example.test')->firstOrFail();

    expect(RoleBinding::query()
        ->where('principal_id', $user->id)
        ->where('scope_type', 'global')
        ->where('role', 'admin')
        ->where('resource_type', 'platform')
        ->where('resource_id', 'racklab')
        ->count())->toBe(1);
});
```

- [ ] **Step 3: Run, confirm fail**

```bash
vendor/bin/pest tests/Contract/Identity/BootstrapAdminGlobalBindingTest.php
```

Expected: fail on the global-binding assertion.

- [ ] **Step 4: Edit `BootstrapAdmin` to add the platform-resource binding (idempotent)**

After the existing project-scope binding creation block, add:

```php
RoleBinding::query()->firstOrCreate(
    [
        'principal_type' => 'user',
        'principal_id' => $user->id,
        'scope_type' => 'global',
        'role' => 'admin',
        'resource_type' => \App\Domain\Tenancy\PlatformResource::RESOURCE_TYPE,
        'resource_id' => \App\Domain\Tenancy\PlatformResource::RACKLAB_ID,
    ],
    [
        'id' => (string) Str::ulid(),
        'tenant_id' => null,
        'tenant_set' => null,
    ],
);
```

Match the surrounding style (field names, type casts).

- [ ] **Step 5: Run test, confirm green**

```bash
vendor/bin/pest tests/Contract/Identity/BootstrapAdminGlobalBindingTest.php
```

Expected: green.

---

## Task 8: HorizonServiceProvider + bootstrap/providers.php

**Files:**
- Modify: `app/Providers/HorizonServiceProvider.php`
- Modify: `bootstrap/providers.php`

- [ ] **Step 1: Replace the published Horizon ServiceProvider stub**

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\HorizonAuthGate;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Horizon::auth covers ALL environments (production AND local). This is
        // the authoritative gate. Gate::define exists as a fallback for direct
        // gate consumers but Horizon's request guard reads Horizon::auth first.
        Horizon::auth(function ($request): bool {
            return app(HorizonAuthGate::class)->authorize($request->user());
        });
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user): bool {
            return app(HorizonAuthGate::class)->authorize($user);
        });
    }
}
```

- [ ] **Step 2: Register in `bootstrap/providers.php`**

```php
<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\PluginServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
```

- [ ] **Step 3: Run gates**

```bash
composer pint:test && composer larastan && composer rector:dry && composer pest:tiny
```

Expected: green.

---

## Task 9: HorizonDashboardAccessTest contract test

**Files:**
- Create: `tests/Contract/Horizon/HorizonDashboardAccessTest.php`

- [ ] **Step 1: Write the contract test**

```php
<?php

declare(strict_types=1);

namespace Tests\Contract\Horizon;

use App\Models\AuditEvent;
use App\Models\RoleBinding;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('returns 403 (NOT a redirect) for anonymous visitors and emits horizon.access.denied audit', function (): void {
    // Horizon's middleware is ['web', BindAuthenticatedTenant::class] — no `auth`.
    // The gate fires for anonymous and returns 403. This keeps the audit gate
    // visible to unauthenticated probes (codex v2 P1 — adding auth would bypass it).
    $this->get('/horizon')->assertForbidden();

    expect(AuditEvent::query()->where('type', 'horizon.access.denied')->count())->toBe(1);
});

it('returns 403 for a user without platform-scope horizon.view', function (): void {
    $user = User::factory()->create();
    // No platform binding created.

    $this->actingAs($user)->get('/horizon')->assertForbidden();

    expect(AuditEvent::query()->where('type', 'horizon.access.denied')->count())->toBeGreaterThanOrEqual(1);
});

it('returns 200 for a user with platform-scope admin binding', function (): void {
    $user = User::factory()->create();
    RoleBinding::query()->create([
        'id' => (string) Str::ulid(),
        'principal_type' => 'user',
        'principal_id' => $user->id,
        'scope_type' => 'global',
        'role' => 'admin',
        'tenant_id' => null,
        'tenant_set' => null,
        'resource_type' => \App\Domain\Tenancy\PlatformResource::RESOURCE_TYPE,
        'resource_id' => \App\Domain\Tenancy\PlatformResource::RACKLAB_ID,
    ]);

    $this->actingAs($user)->get('/horizon')->assertOk();

    expect(AuditEvent::query()->where('type', 'horizon.access')->count())->toBeGreaterThanOrEqual(1);
});

it('OVER-AUTH REGRESSION GUARD: returns 403 for a user with global-scope binding on a project (not the platform resource)', function (): void {
    $user = User::factory()->create();
    RoleBinding::query()->create([
        'id' => (string) Str::ulid(),
        'principal_type' => 'user',
        'principal_id' => $user->id,
        'scope_type' => 'global',
        'role' => 'admin',
        'tenant_id' => null,
        'tenant_set' => null,
        'resource_type' => 'project',   // NOT 'platform'
        'resource_id' => 'project-xyz',
    ]);

    $this->actingAs($user)->get('/horizon')->assertForbidden();
});
```

- [ ] **Step 2: Run, confirm green** (HorizonServiceProvider + AuthGate + permittedPlatform already in place)

```bash
vendor/bin/pest tests/Contract/Horizon/HorizonDashboardAccessTest.php
```

Expected: 3 passed.

---

## Task 10: Audit-events snapshot update

**Files:**
- Modify: `tests/Snapshots/audit-events.json`

- [ ] **Step 1: Run the snapshot suite, see the gap**

```bash
vendor/bin/pest tests/Snapshots/AuditEventsTest.php
```

- [ ] **Step 2: Edit `tests/Snapshots/audit-events.json` — add `horizon.access` and `horizon.access.denied` in alphabetical order**

- [ ] **Step 3: Re-run, confirm green**

```bash
vendor/bin/pest tests/Snapshots/AuditEventsTest.php
```

---

## Task 11: BindTenantContext Spatie fix + TenantLeakBetweenJobsTest

**Files:**
- Modify: `app/Jobs/Middleware/BindTenantContext.php`
- Create: `tests/Integration/TenantLeakBetweenJobsTest.php`

- [ ] **Step 1: Write the failing integration test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Tenant;
use Tests\TestCase;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

uses(TestCase::class);

it('clears Spatie tenant context between two sync-queued jobs on different tenants', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Job A makes tenantA current then exits
    $jobA = new \App\Jobs\Test\AssertCurrentTenantJob(tenantId: $tenantA->id, expected: $tenantA->id);
    // Job B makes tenantB current; if Spatie tenant leaked, this would see tenantA
    $jobB = new \App\Jobs\Test\AssertCurrentTenantJob(tenantId: $tenantB->id, expected: $tenantB->id);

    dispatch_sync($jobA);
    dispatch_sync($jobB);

    // After both dispatches, no tenant should be current
    expect(SpatieTenant::current())->toBeNull();
});
```

The test job class `App\Jobs\Test\AssertCurrentTenantJob` is a test-only TenantAwareJob that:
1. Asserts `SpatieTenant::current()->id === $this->expected` inside `handle()`.
2. Uses `BindTenantContext` middleware (declared via `middleware()` array).

If `BindTenantContext` doesn't drive Spatie's tenant, the second dispatch sees tenantA's tenant from the first job, the assertion fails, and the test fails.

- [ ] **Step 2: Run, confirm fail**

```bash
vendor/bin/pest tests/Integration/TenantLeakBetweenJobsTest.php
```

Expected: fail — Spatie tenant leaks between jobs.

- [ ] **Step 3: Update `BindTenantContext`**

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\Contracts\TenantAwareJob;
use App\Models\Tenant;
use Closure;

final readonly class BindTenantContext
{
    public function __construct(private TenantContextStore $tenantContext) {}

    /**
     * @param  Closure(TenantAwareJob): mixed  $next
     */
    public function handle(TenantAwareJob $job, Closure $next): mixed
    {
        $this->tenantContext->forget();
        Tenant::forgetCurrent();

        $tenant = Tenant::query()->findOrFail($job->tenantId());
        $this->tenantContext->set(new TenantContext(activeTenantId: $tenant->id));
        $tenant->makeCurrent();

        try {
            return $next($job);
        } finally {
            $this->tenantContext->forget();
            Tenant::forgetCurrent();
        }
    }
}
```

- [ ] **Step 4: Re-run, confirm green**

```bash
vendor/bin/pest tests/Integration/TenantLeakBetweenJobsTest.php
```

Expected: green.

- [ ] **Step 5: Make sure existing contract tests for tenancy still pass**

```bash
composer pest:contract --filter="Tenancy"
```

Expected: green.

---

## Task 12: Commit Horizon foundation

- [ ] **Step 1: Stage**

```bash
git add composer.json composer.lock .env.example \
    config/horizon.php public/vendor/horizon/ \
    app/Auth/ app/Providers/HorizonServiceProvider.php \
    app/Domain/Tenancy/AccessResolver.php app/Domain/Tenancy/RoleBindingRepository.php \
    app/Tenancy/EloquentRoleBindingRepository.php \
    app/Domain/Rbac/DefaultRoleCatalog.php \
    app/Console/Commands/BootstrapAdmin.php \
    app/Jobs/Middleware/BindTenantContext.php \
    bootstrap/providers.php \
    database/migrations/2026_05_28_000001_make_audit_actor_tenant_nullable.php \
    tests/Tiny/Horizon/ tests/Tiny/Tenancy/AccessResolverPlatformTest.php \
    tests/Contract/Horizon/ tests/Contract/Identity/BootstrapAdminGlobalBindingTest.php \
    tests/Integration/TenantLeakBetweenJobsTest.php \
    tests/Snapshots/roles.json tests/Snapshots/audit-events.json
```

- [ ] **Step 2: Commit**

```bash
git commit -m "$(cat <<'EOF'
feat(queue): install + wire laravel/horizon v5.47.1

Adds Horizon v5.47.1 as the queue supervisor. Configures four
supervisor pools (provider, scripts, console, notifications) with
queue names matching the actual job dispatches (provider-worker,
script-worker, console-worker, notification-worker) — fixing a
pre-existing latent bug where workers listened on the wrong queues.

`AccessResolver::permittedPlatform()` is the new gate for platform-
scope resources; HorizonAuthGate calls it with horizon.view. Bootstrap
admin gains a global-scope admin binding alongside the existing
project-scope binding. BindTenantContext now drives Spatie's current
tenant, closing a worker leak. audit_events.actor_tenant becomes
nullable for anonymous denial rows. REDIS_QUEUE_RETRY_AFTER bumped to
3700 so the longest queue timeout stays < retry_after.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

Expected: lefthook gates pass, commit lands.

---

## Task 13: Two-Quadlet topology + installer update

**Files:**
- Create: `deploy/quadlets/racklab-horizon-app.container`
- Create: `deploy/quadlets/racklab-horizon-runner.container`
- Delete: four legacy worker Quadlets
- Modify: `deploy/quadlets/racklab-runtime.target`
- Modify: `scripts/baseline-install.sh`
- Modify: `tests/Integration/BaselineInstallScriptTest.php`

- [ ] **Step 1: Write `racklab-horizon-app.container` (no Podman socket)**

```ini
[Unit]
Description=RackLab Horizon (app pool: provider + notifications)
Documentation=https://github.com/cyberbalsa/racklab
Requires=racklab-postgres.service racklab-redis.service racklab-plugin-bootstrap.service
After=racklab-postgres.service racklab-redis.service racklab-plugin-bootstrap.service
PartOf=racklab-runtime.target

[Container]
Image=ghcr.io/cyberbalsa/racklab/horizon:main
ContainerName=racklab-horizon-app
Network=racklab.network
EnvironmentFile=/etc/racklab/racklab.env
Environment=RACKLAB_HORIZON_POOL_GROUP=app
Volume=/etc/racklab:/etc/racklab:ro,Z
Volume=/var/lib/racklab/storage:/var/www/html/storage:Z
Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z
Exec=php artisan horizon
StopSignal=SIGTERM
StopTimeout=3700
NoNewPrivileges=true
Pull=newer

[Service]
Restart=always
TimeoutStartSec=300
TimeoutStopSec=3730

[Install]
WantedBy=racklab-runtime.target
```

> **v3 note:** plugin volume is `:ro,Z` — runtime containers cannot write plugin code. Only `racklab-plugin-bootstrap.container` retains write access for plugin install/migrate.

- [ ] **Step 2: Write `racklab-horizon-runner.container` (WITH Podman socket, read-only plugin mount)**

```ini
[Unit]
Description=RackLab Horizon (runner pool: scripts + console)
Documentation=https://github.com/cyberbalsa/racklab
Requires=racklab-postgres.service racklab-redis.service racklab-plugin-bootstrap.service
After=racklab-postgres.service racklab-redis.service racklab-plugin-bootstrap.service
PartOf=racklab-runtime.target

[Container]
Image=ghcr.io/cyberbalsa/racklab/horizon:main
ContainerName=racklab-horizon-runner
Network=racklab.network
EnvironmentFile=/etc/racklab/racklab.env
Environment=RACKLAB_HORIZON_POOL_GROUP=runner
Environment=CONTAINER_HOST=unix:///run/podman/podman.sock
Volume=/etc/racklab:/etc/racklab:ro,Z
Volume=/var/lib/racklab/storage:/var/www/html/storage:Z
Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z
Volume=/run/podman/podman.sock:/run/podman/podman.sock
Exec=php artisan horizon
StopSignal=SIGTERM
StopTimeout=3700
NoNewPrivileges=true
Pull=newer

[Service]
Restart=always
TimeoutStartSec=300
TimeoutStopSec=3730

[Install]
WantedBy=racklab-runtime.target
```

- [ ] **Step 2.5: Tighten plugin volumes :ro,Z on other runtime Quadlets (codex v3 P2)**

The plugin volume should be writable in ONLY `racklab-plugin-bootstrap.container`. Make it read-only in every other runtime container:

```bash
# Verify which Quadlets currently mount plugins (and how):
grep -rE 'racklab/plugins:/var/lib/racklab/plugins' deploy/quadlets/*.container
```

For each runtime Quadlet (`racklab-web.container`, `racklab-reverb.container`, `racklab-scheduler-reconciler@.container`), change `Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:Z` to `Volume=/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z`.

LEAVE `racklab-plugin-bootstrap.container` UNCHANGED — that's the sole writer.

Extend `BaselineInstallScriptTest` with a new assertion:

```php
it('mounts plugin volume :ro,Z in all runtime Quadlets except plugin-bootstrap', function (): void {
    foreach (['racklab-web', 'racklab-reverb', 'racklab-scheduler-reconciler@', 'racklab-horizon-app', 'racklab-horizon-runner'] as $unit) {
        $content = file_get_contents(base_path("deploy/quadlets/{$unit}.container"));
        expect($content)->toContain('/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z', "{$unit} should mount plugins :ro,Z");
    }

    // Bootstrap retains write access
    $bootstrap = file_get_contents(base_path('deploy/quadlets/racklab-plugin-bootstrap.container'));
    expect($bootstrap)->not->toContain(':ro,Z');
});
```

- [ ] **Step 3: Delete the four legacy worker Quadlets**

```bash
git rm deploy/quadlets/racklab-provider-worker@.container \
       deploy/quadlets/racklab-script-worker@.container \
       deploy/quadlets/racklab-console-worker@.container \
       deploy/quadlets/racklab-notification-worker@.container
```

- [ ] **Step 4: Update `racklab-runtime.target`**

Current `Wants=` line:
```
Wants=racklab-network.service racklab-postgres.service racklab-redis.service racklab-plugin-bootstrap.service racklab-web.service racklab-reverb.service racklab-provider-worker@1.service racklab-script-worker@1.service racklab-console-worker@1.service racklab-scheduler-reconciler@1.service racklab-notification-worker@1.service
```

New:
```
Wants=racklab-network.service racklab-postgres.service racklab-redis.service racklab-plugin-bootstrap.service racklab-web.service racklab-reverb.service racklab-horizon-app.service racklab-horizon-runner.service racklab-scheduler-reconciler@1.service
```

- [ ] **Step 5: Extend `BaselineInstallScriptTest`**

Add to `tests/Integration/BaselineInstallScriptTest.php`:

```php
it('renders both horizon-app and horizon-runner Quadlets', function (): void {
    $unitDir = $this->renderQuadletsToTempDir();

    expect(file_exists("$unitDir/racklab-horizon-app.container"))->toBeTrue();
    expect(file_exists("$unitDir/racklab-horizon-runner.container"))->toBeTrue();

    $appContent = file_get_contents("$unitDir/racklab-horizon-app.container");
    $runnerContent = file_get_contents("$unitDir/racklab-horizon-runner.container");

    // app pool MUST NOT mount the Podman socket
    expect($appContent)->not->toContain('podman.sock');
    expect($appContent)->toContain('RACKLAB_HORIZON_POOL_GROUP=app');

    // runner pool MUST mount the Podman socket
    expect($runnerContent)->toContain('podman.sock');
    expect($runnerContent)->toContain('RACKLAB_HORIZON_POOL_GROUP=runner');

    // v3: plugin volume MUST be :ro,Z on BOTH runtime containers
    expect($appContent)->toContain('/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z');
    expect($runnerContent)->toContain('/var/lib/racklab/plugins:/var/lib/racklab/plugins:ro,Z');

    foreach (['racklab-provider-worker', 'racklab-script-worker', 'racklab-console-worker', 'racklab-notification-worker'] as $legacy) {
        expect(file_exists("$unitDir/{$legacy}@.container"))->toBeFalse();
    }
});

it('removes legacy worker units cleanly on upgrade', function (): void {
    $unitDir = $this->seedLegacyQuadletsInTempDir();

    $this->runBaselineInstaller(args: ['--upgrade', '--unit-dir=' . $unitDir]);

    foreach (['racklab-provider-worker', 'racklab-script-worker', 'racklab-console-worker', 'racklab-notification-worker'] as $legacy) {
        expect(file_exists("$unitDir/{$legacy}@.container"))->toBeFalse();
    }
});
```

- [ ] **Step 6: Run, confirm fail**

```bash
vendor/bin/pest tests/Integration/BaselineInstallScriptTest.php
```

Expected: fail on the new assertions.

- [ ] **Step 7: Update `scripts/baseline-install.sh`**

Read the existing installer; find the Quadlet-rendering section. Replace the listing of four legacy worker Quadlets with the two new ones (`racklab-horizon-app.container`, `racklab-horizon-runner.container`). Add a legacy-cleanup block:

```bash
legacy_units=(
  racklab-provider-worker@.container
  racklab-script-worker@.container
  racklab-console-worker@.container
  racklab-notification-worker@.container
)
for unit in "${legacy_units[@]}"; do
  if [[ -f "${RACKLAB_UNIT_DIR}/${unit}" ]]; then
    if [[ "${RACKLAB_SKIP_SYSTEMD}" != "1" ]]; then
      systemctl --user disable --now "${unit%.container}@1.service" 2>/dev/null || true
    fi
    rm -f "${RACKLAB_UNIT_DIR}/${unit}"
  fi
done
```

Match the surrounding shell style and respect `--dry-run` / `--skip-systemd` flags.

- [ ] **Step 8: Re-run, confirm green**

```bash
vendor/bin/pest tests/Integration/BaselineInstallScriptTest.php
```

Expected: green.

---

## Task 14: Commit Quadlet refactor

```bash
git add deploy/quadlets/ scripts/baseline-install.sh tests/Integration/BaselineInstallScriptTest.php

git commit -m "$(cat <<'EOF'
chore(deploy): split Horizon onto app + runner Quadlets

Replaces four legacy worker Quadlets with two Horizon containers that
partition by Podman-socket exposure:
- racklab-horizon-app: provider + notification supervisors, no socket.
- racklab-horizon-runner: script + console supervisors, socket mounted.

config/horizon.php selects supervisors based on
RACKLAB_HORIZON_POOL_GROUP, so a single image serves both Quadlets.
baseline-install.sh idempotently removes the four legacy units on
upgrade. The privilege boundary preserved here is that provider and
notification jobs cannot reach the host Podman socket — closing the
v1-spec widening that codex caught.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: Containerfile + image-matrix collapse + legacy mirror tags

**Files:**
- Modify: `Containerfile`
- Modify: `.github/workflows/build-images.yml`
- Modify: `tests/Integration/BuildImagesWorkflowTest.php`

- [ ] **Step 1: Extend `BuildImagesWorkflowTest` with the failing matrix assertion**

```php
it('uses the four-target horizon-collapsed matrix', function (): void {
    $workflow = parseYaml('.github/workflows/build-images.yml');
    $targets = $workflow['jobs']['build']['strategy']['matrix']['target'] ?? [];

    expect($targets)->toEqualCanonicalizing(['web', 'reverb', 'horizon', 'scheduler-reconciler']);
});

it('declares the horizon target in Containerfile', function (): void {
    $containerfile = file_get_contents(base_path('Containerfile'));
    expect($containerfile)->toMatch('/^FROM .* AS horizon$/m');
    foreach (['provider-worker', 'script-worker', 'console-worker', 'notification-worker'] as $obsolete) {
        expect($containerfile)->not->toMatch("/^FROM .* AS {$obsolete}\$/m");
    }
});

it('publishes legacy mirror tags from the horizon image for one release cycle', function (): void {
    $workflow = parseYaml('.github/workflows/build-images.yml');
    $steps = $workflow['jobs']['build']['steps'];
    $names = array_column($steps, 'name');
    expect($names)->toContain('Tag legacy aliases for one release cycle');
});
```

- [ ] **Step 2: Run, confirm fail**

- [ ] **Step 3: Update `Containerfile` — collapse the four targets**

Find the existing `FROM ... AS provider-worker|script-worker|console-worker|notification-worker` stanzas. Replace with a single `FROM <runtime-base> AS horizon` target. Move shared setup ahead of the target labels; the runtime `CMD` / `ENTRYPOINT` is invoked from the Quadlet (`Exec=php artisan horizon`), so the image just needs the binary on PATH.

- [ ] **Step 4: Update `.github/workflows/build-images.yml`**

Find the matrix and shrink to `[web, reverb, horizon, scheduler-reconciler]`.

Add a step after the publish step:

```yaml
- name: Tag legacy aliases for one release cycle
  if: matrix.target == 'horizon' && github.event_name != 'pull_request'
  run: |
    for alias in provider-worker script-worker console-worker notification-worker; do
      docker buildx imagetools create \
        --tag ghcr.io/cyberbalsa/racklab/${alias}:sha-${{ github.sha }} \
        ghcr.io/cyberbalsa/racklab/horizon:sha-${{ github.sha }}
    done
```

Match the existing publish-step authentication style (the workflow already authenticates docker for GHCR).

- [ ] **Step 5: Run workflow-shape test, confirm green**

```bash
vendor/bin/pest tests/Integration/BuildImagesWorkflowTest.php
```

---

## Task 16: Commit image refactor

```bash
git add Containerfile .github/workflows/build-images.yml tests/Integration/BuildImagesWorkflowTest.php

git commit -m "$(cat <<'EOF'
build: collapse Horizon worker targets in Containerfile + image matrix

Merges provider-worker, script-worker, console-worker, notification-worker
Containerfile targets into a single horizon target. build-images.yml
matrix shrinks from seven to four targets. Legacy image tags continue
to publish for one release cycle as docker buildx imagetools aliases
pointing at the horizon image, so external integrators don't break.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 17: HorizonWorkerSmokeTest integration

**Files:**
- Create: `tests/Integration/HorizonWorkerSmokeTest.php`

- [ ] **Step 1: Write the Redis-gated smoke test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! redisAvailable()) {
        $this->markTestSkipped('Redis not available for Horizon smoke');
    }
});

it('boots Horizon under RACKLAB_HORIZON_POOL_GROUP=all and processes a FakeContainerRuntime job', function (): void {
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.retry_after', 3700);

    // ... dispatch a fake script run job onto the script-worker queue ...

    $proc = new Process(['php', 'artisan', 'horizon', '--environment=testing'], base_path(), ['RACKLAB_HORIZON_POOL_GROUP' => 'all']);
    $proc->setTimeout(10);
    try {
        $proc->run();
    } catch (\Throwable) {
        // Process timed out — Horizon ran for the budget, that's the green path.
    }

    expect(\App\Models\ScriptRun::query()->where('state', 'succeeded')->count())->toBeGreaterThanOrEqual(1);
});

function redisAvailable(): bool
{
    try {
        \Illuminate\Support\Facades\Redis::connection('default')->ping();
        return true;
    } catch (\Throwable) {
        return false;
    }
}
```

> **Note:** The exact dispatch call depends on the existing `RunScriptContainer` constructor signature. Read it first; the test must compose the same script + project + version fixture that the existing fake-runtime tests build.

- [ ] **Step 2: Run** — likely skipped in this Toolbx, green in Redis-capable CI

```bash
vendor/bin/pest tests/Integration/HorizonWorkerSmokeTest.php
```

---

## Task 18: Browser /horizon navigation extension

**Files:**
- Modify: `tests/Browser/FilamentAdminWorkflowTest.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (to surface the Horizon link)

- [ ] **Step 1: Add a Horizon link to the Filament admin panel**

In `AdminPanelProvider::panel()`, add a navigation item (or item-action) that links to `/horizon` when the user can access it. Match Filament's existing pattern in this codebase.

- [ ] **Step 2: Add the browser test step**

```php
it('admin can navigate from Filament panel to /horizon', function (): void {
    $admin = User::factory()->create();
    \App\Models\RoleBinding::query()->create([
        'id' => (string) \Illuminate\Support\Str::ulid(),
        'principal_type' => 'user', 'principal_id' => $admin->id,
        'scope_type' => 'global', 'role' => 'admin',
        'tenant_id' => null, 'tenant_set' => null,
        'resource_type' => null, 'resource_id' => null,
    ]);

    $this->browse(function (Browser $browser) use ($admin): void {
        $browser->loginAs($admin)
            ->visit('/admin')
            ->assertSee('Horizon')
            ->clickLink('Horizon')
            ->waitForLocation('/horizon')
            ->assertPathIs('/horizon');
    });
});
```

- [ ] **Step 3: Run browser gate**

```bash
composer pest:browser
```

Expected: existing 8 + new 1 = 9 passed; axe-core remains clean.

---

## Task 19: Commit integration + browser

```bash
git add tests/Integration/HorizonWorkerSmokeTest.php tests/Browser/FilamentAdminWorkflowTest.php app/Providers/Filament/

git commit -m "$(cat <<'EOF'
test: cover Horizon worker smoke and admin /horizon navigation

Adds Redis-gated integration smoke that boots Horizon under POOL_GROUP=all
and processes a FakeContainerRuntime job through the script-worker
queue. Extends the Filament browser workflow to navigate from /admin
to /horizon for a global-admin user; axe-core remains clean.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 20: Dependabot config + test

**Files:**
- Create: `.github/dependabot.yml`
- Create: `tests/Integration/DependabotConfigurationTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

uses(TestCase::class);

it('declares the four ecosystems', function (): void {
    $config = \Symfony\Component\Yaml\Yaml::parseFile(base_path('.github/dependabot.yml'));
    expect($config['version'])->toBe(2);

    $ecosystems = array_column($config['updates'], 'package-ecosystem');
    expect($ecosystems)->toEqualCanonicalizing(['composer', 'npm', 'github-actions', 'docker']);
});

it('uses conventional-commit prefixes', function (): void {
    $config = \Symfony\Component\Yaml\Yaml::parseFile(base_path('.github/dependabot.yml'));
    foreach ($config['updates'] as $update) {
        expect($update['commit-message']['prefix'])->toBeIn(['build(deps)', 'ci(deps)']);
    }
});
```

- [ ] **Step 2: Write `.github/dependabot.yml`** (content from spec §8)

- [ ] **Step 3: Run, confirm green**

```bash
vendor/bin/pest tests/Integration/DependabotConfigurationTest.php
```

---

## Task 21: Commit Dependabot

```bash
git add .github/dependabot.yml tests/Integration/DependabotConfigurationTest.php
git commit -m "ci(deps): enable Dependabot for composer/npm/actions/docker

Closes the audit gap surfaced by codex (no dependabot.yml exists).
Weekly Monday cadence, grouped minor/patch updates, conventional-commit
prefixes. Bot-PR commits will not be Bitwarden-signed; documented in
docs/prd/17.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 22: Grype two-scan + `.grype.yaml` + security-events permission

**Files:**
- Create: `.grype.yaml` (repo root)
- Modify: `.github/workflows/build-images.yml`
- Modify: `tests/Integration/BuildImagesWorkflowTest.php`

- [ ] **Step 1: Extend the workflow test**

```php
it('runs the two-scan Grype model (full + fixed-only) and uploads SARIF', function (): void {
    $workflow = parseYaml('.github/workflows/build-images.yml');
    $steps = $workflow['jobs']['build']['steps'];
    $names = array_column($steps, 'name');

    expect($names)->toContain('Grype full report');
    expect($names)->toContain('Upload full SARIF');
    expect($names)->toContain('Grype fixed-CVE failure gate');

    $full = current(array_filter($steps, fn ($s) => ($s['name'] ?? '') === 'Grype full report'));
    expect($full['uses'])->toBe('anchore/scan-action@v7');
    expect($full['with']['fail-build'])->toBe(false);
    expect($full['with']['only-fixed'])->toBe(false);

    $gate = current(array_filter($steps, fn ($s) => ($s['name'] ?? '') === 'Grype fixed-CVE failure gate'));
    expect($gate['uses'])->toBe('anchore/scan-action@v7');
    expect($gate['with']['fail-build'])->toBe(true);
    expect($gate['with']['only-fixed'])->toBe(true);
    expect($gate['with']['severity-cutoff'])->toBe('high');
    expect($gate['with']['config'])->toBe('.grype.yaml');

    $upload = current(array_filter($steps, fn ($s) => ($s['name'] ?? '') === 'Upload full SARIF'));
    expect($upload['uses'])->toBe('github/codeql-action/upload-sarif@v4');
});

it('declares security-events: write permission for SARIF upload', function (): void {
    $workflow = parseYaml('.github/workflows/build-images.yml');
    expect($workflow['permissions']['security-events'] ?? null)->toBe('write');
});

it('places .grype.yaml at repo root with an empty initial allowlist', function (): void {
    expect(file_exists(base_path('.grype.yaml')))->toBeTrue();
    $config = \Symfony\Component\Yaml\Yaml::parseFile(base_path('.grype.yaml'));
    expect($config['ignore'] ?? [])->toBe([]);
});
```

- [ ] **Step 2: Write `.grype.yaml`** at repo root:

```yaml
# Anchore Grype policy for RackLab image builds.
# See docs/superpowers/specs/2026-05-28-horizon-and-supply-chain-design.md.
# Add ignore rules below ONLY with: CVE id, package, rationale, expiry date.
ignore: []
```

- [ ] **Step 3: Update `.github/workflows/build-images.yml`**

Add `security-events: write` to the top-level `permissions:` block. After the existing Syft SBOM step, add the two Grype steps + SARIF upload as written in spec §9.

- [ ] **Step 4: Run, confirm green**

```bash
vendor/bin/pest tests/Integration/BuildImagesWorkflowTest.php
```

---

## Task 23: Commit Grype

```bash
git add .grype.yaml .github/workflows/build-images.yml tests/Integration/BuildImagesWorkflowTest.php

git commit -m "$(cat <<'EOF'
ci(images): scan Syft SBOMs with Anchore Grype (two-scan model)

Adds two Grype scans on the Syft SBOMs already being generated:
- Full report (severity-cutoff=low, only-fixed=false) — uploaded to
  GitHub code-scanning as SARIF for full visibility of unfixed CVEs.
- Fixed-only failure gate (severity-cutoff=high, only-fixed=true) —
  blocks the workflow on high+ CVEs with upstream fixes available.

Uses anchore/scan-action@v7 (Node 24) and
github/codeql-action/upload-sarif@v4. Workflow gains
security-events: write permission. .grype.yaml at repo root carries
the allowlist (initially empty); the documented exception pattern is
CVE id + package + rationale + expiry date.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 24: Self-hosted runner script (stdin token + checksum) + service template + test

**Files:**
- Create: `scripts/dev/register-host-runner.sh`
- Create: `scripts/dev/racklab-self-hosted-runner.service.template`
- Create: `tests/Integration/SelfHostedRunnerScriptTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

it('declares the required runner labels', function (): void {
    $script = file_get_contents(base_path('scripts/dev/register-host-runner.sh'));
    foreach (['self-hosted', 'linux', 'podman', 'cgroup-delegated'] as $label) {
        expect($script)->toContain($label);
    }
});

it('refuses --token= flag (use --token-file or stdin)', function (): void {
    $script = file_get_contents(base_path('scripts/dev/register-host-runner.sh'));
    expect($script)->not->toMatch('/--token=/');
    expect($script)->toMatch('/--token-file|read TOKEN/');
});

it('verifies the runner archive sha256 before extraction', function (): void {
    $script = file_get_contents(base_path('scripts/dev/register-host-runner.sh'));
    expect($script)->toContain('sha256sum')->toContain('.tar.gz.sha256');
});

it('refuses overwrite without --reconfigure', function (): void {
    $script = file_get_contents(base_path('scripts/dev/register-host-runner.sh'));
    expect($script)->toContain('--reconfigure');
});

it('publishes a systemd-user unit template with restart', function (): void {
    $template = file_get_contents(base_path('scripts/dev/racklab-self-hosted-runner.service.template'));
    expect($template)->toContain('[Service]')->toContain('Restart=always');
});
```

- [ ] **Step 2: Author `scripts/dev/register-host-runner.sh`** (stdin/file token, checksum verify):

```bash
#!/usr/bin/env bash
# Register the workstation host as a labelled self-hosted GHA runner
# for RackLab. Token MUST come from stdin or --token-file (NOT a flag —
# flags leak into shell history / process listings).
set -euo pipefail

REPO_URL="https://github.com/cyberbalsa/racklab"
RUNNER_VERSION="2.319.1"
RUNNER_SHA256_URL_BASE="https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}"
RUNNER_HOME="${RACKLAB_RUNNER_HOME:-$HOME/.racklab/actions-runner}"
LABELS="self-hosted,linux,podman,cgroup-delegated"

usage() {
  cat <<EOF
Usage: $0 [--token-file=PATH] [--reconfigure] [--noop]

Registers this host as a self-hosted GitHub Actions runner for $REPO_URL
with labels: $LABELS

The registration token must come from stdin (default) or --token-file.
Passing --token= on the command line is NOT supported — it would leak
the token into shell history and ps output.

Options:
  --token-file=PATH    Read the registration token from PATH.
  --reconfigure        Replace an existing runner config.
  --noop               Print what would happen, do not register.
EOF
}

TOKEN_FILE=""
RECONFIGURE=0
NOOP=0

for arg in "$@"; do
  case "$arg" in
    --token-file=*) TOKEN_FILE="${arg#--token-file=}" ;;
    --reconfigure) RECONFIGURE=1 ;;
    --noop) NOOP=1 ;;
    --token=*) echo "Error: --token= leaks into shell history; use --token-file or stdin." >&2; exit 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage; exit 2 ;;
  esac
done

if [[ "$NOOP" -ne 1 ]]; then
  if [[ -n "$TOKEN_FILE" ]]; then
    TOKEN="$(cat "$TOKEN_FILE")"
  else
    read -r -s -p "Registration token (input hidden): " TOKEN
    echo
  fi
  [[ -z "$TOKEN" ]] && { echo "Empty token; refusing to proceed." >&2; exit 2; }
fi

echo "Registering self-hosted runner:"
echo "  REPO_URL=$REPO_URL"
echo "  RUNNER_HOME=$RUNNER_HOME"
echo "  LABELS=$LABELS"

if [[ "$NOOP" -eq 1 ]]; then
  echo "(noop)"
  exit 1
fi

if [[ -d "$RUNNER_HOME" && "$RECONFIGURE" -ne 1 ]]; then
  echo "Error: $RUNNER_HOME already exists. Re-run with --reconfigure to replace." >&2
  exit 3
fi

mkdir -p "$RUNNER_HOME"
cd "$RUNNER_HOME"

if [[ ! -x ./config.sh ]]; then
  ARCHIVE="actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
  curl -fsSL -o "$ARCHIVE" "${RUNNER_SHA256_URL_BASE}/${ARCHIVE}"
  curl -fsSL -o "${ARCHIVE}.sha256" "${RUNNER_SHA256_URL_BASE}/${ARCHIVE}.sha256"
  echo "$(cat "${ARCHIVE}.sha256")  ${ARCHIVE}" | sha256sum -c -
  tar xzf "$ARCHIVE"
  rm "$ARCHIVE" "${ARCHIVE}.sha256"
fi

./config.sh \
  --url "$REPO_URL" \
  --token "$TOKEN" \
  --labels "$LABELS" \
  --unattended \
  --replace

echo
echo "Runner registered. Enable systemd-user service:"
echo "  systemctl --user daemon-reload && systemctl --user enable --now racklab-self-hosted-runner.service"
```

`chmod +x scripts/dev/register-host-runner.sh`.

- [ ] **Step 3: Author the systemd-user template**

```ini
[Unit]
Description=RackLab self-hosted GitHub Actions runner
After=network-online.target

[Service]
Type=simple
WorkingDirectory=%h/.racklab/actions-runner
Environment=RACKLAB_RUNNER_HOME=%h/.racklab/actions-runner
ExecStart=%h/.racklab/actions-runner/run.sh
Restart=always
RestartSec=10

[Install]
WantedBy=default.target
```

- [ ] **Step 4: Run, confirm green**

```bash
vendor/bin/pest tests/Integration/SelfHostedRunnerScriptTest.php
```

---

## Task 25: Commit runner script

```bash
git add scripts/dev/ tests/Integration/SelfHostedRunnerScriptTest.php
git commit -m "$(cat <<'EOF'
chore(scripts): prep self-hosted Podman runner registration

scripts/dev/register-host-runner.sh registers this workstation host as
a labelled (self-hosted,linux,podman,cgroup-delegated) GHA runner for
podman-runtime-ci.yml. Token must come from stdin or --token-file —
--token= is refused because it would leak into shell history.

The runner archive is sha256-verified before extraction. systemd-user
unit template keeps the runner alive across reboots. Idempotent —
refuses to overwrite without --reconfigure.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 26: Docs cleanup

**Files:**
- Modify: `docs/prd/17-engineering-quality-typing-ci.md`
- Modify: `PROGRESS.md`
- Modify: `CLAUDE.md`
- Modify: `AGENTS.md`

- [ ] **Step 1: PRD §17 — drop enlightn paragraph; add Grype line; note Dependabot signing**

Find the enlightn paragraph; remove. Add (in a sibling paragraph):

> Image-level CVE scanning runs Anchore Grype in `build-images.yml`. Grype reads the Syft SBOMs already generated. A full SARIF report uploads to GitHub code scanning; a fixed-only `severity-cutoff=high` gate blocks builds with upstream-fixed CVEs. The `.grype.yaml` allowlist follows the same exception pattern as `.github/license-policy.allowlist.json` (CVE id + package + rationale + expiry).
>
> Dependabot bot-PR commits are not signed by the local Bitwarden SSH agent. Maintainers re-sign on merge. See `.github/dependabot.yml`.

- [ ] **Step 2: PROGRESS.md — strip stale notes, add Horizon-shipped block**

In "Next":
- Item #1 (`baseline-worker-host-soak`): remove the "Horizon remains a dependency gap" sentence.
- Item #3 (`ci-gates`): rewrite to "Add any final custom Larastan behavior gaps that emerge from the MVP code surface." Remove the Node 20 + enlightn sentences (both addressed).

Insert a new "shipped" block above the "Next" header:

```markdown
The Horizon install + supply-chain hardening increment is in place:

- `laravel/horizon` v5.47.1 is installed; the four legacy worker
  Quadlets are replaced by TWO Horizon containers partitioned by
  Podman-socket exposure (`racklab-horizon-app` for provider +
  notifications without socket; `racklab-horizon-runner` for scripts
  + console with socket). `config/horizon.php` partitions supervisors
  via `RACKLAB_HORIZON_POOL_GROUP`. Supervisor queue names now match
  the actual job dispatches (`provider-worker`, `script-worker`,
  `console-worker`, `notification-worker`), fixing a pre-existing
  latent bug.
- `AccessResolver::permittedPlatform()` gates platform-scope resources
  (Horizon, future admin endpoints) without faking tenant context.
  `App\Auth\HorizonAuthGate` calls it via `Horizon::auth()` (covers
  all environments). Bootstrap admin gets a global-scope admin
  binding alongside its project-scope one.
- `BindTenantContext` job middleware now drives Spatie's
  `Tenant::makeCurrent()` / `forgetCurrent()`, closing a real tenant
  leak between Horizon-driven jobs.
- `audit_events.actor_tenant` is now nullable so anonymous denial
  rows can be written (e.g., un-authed /horizon visits).
- `REDIS_QUEUE_RETRY_AFTER` bumped to 3700 so the longest queue
  timeout stays < retry_after.
- `.github/dependabot.yml` enables Dependabot for composer, npm,
  github-actions, docker.
- `.github/workflows/build-images.yml` adds a two-scan Anchore Grype
  pipeline: full SARIF report (non-blocking, full visibility) plus a
  fixed-only failure gate. Uses `anchore/scan-action@v7` (Node 24) +
  `github/codeql-action/upload-sarif@v4`. `.grype.yaml` at repo root.
  `security-events: write` permission declared.
- `scripts/dev/register-host-runner.sh` registers a labelled
  self-hosted GHA runner on the workstation host (stdin token,
  sha256-verified archive).
- The `enlightn/security-checker` paragraph is removed from
  `docs/prd/17` — it duplicated `composer audit` against the same
  upstream advisory database without lifting the Symfony 8 cap.
```

- [ ] **Step 3: CLAUDE.md + AGENTS.md stack-table update**

```
| Queue + jobs | Horizon (Redis; requires `ext-pcntl` + `ext-posix`, both declared explicit composer requires) | v5.47.1 (installed) |
```

---

## Task 27: Commit docs

```bash
git add docs/prd/17-engineering-quality-typing-ci.md PROGRESS.md CLAUDE.md AGENTS.md
git commit -m "docs: record Horizon install + drop enlightn / stale Node 20 notes

PROGRESS.md gains the Horizon-shipped block. \"Next\" drops the
Horizon-blocked claim, Node 20 reference, and enlightn paragraph.
docs/prd/17 swaps the enlightn paragraph for a Grype description and
notes the Dependabot bot-PR signing exception. CLAUDE.md/AGENTS.md
stack-table Queue+jobs row points at v5.47.1 with explicit pcntl/posix
requires.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 28: Codex cross-review of the full branch

- [ ] **Step 1: Kick off codex in background**

```bash
tmpfile=$(mktemp /tmp/codex-horizon-branch.XXXXXX.md)
codex review --uncommitted --dangerously-bypass-approvals-and-sandbox \
  > "$tmpfile" 2>&1 &
```

(Note: with all commits staged in main, use `codex review main..HEAD` if `--uncommitted` is empty.)

- [ ] **Step 2: Read findings after completion notification**

Read the tmpfile. Classify P0/P1/P2/P3.

- [ ] **Step 3: Fold P0/P1 into follow-up commits**

For each P0/P1 finding, write a small targeted commit.

---

## Task 29: Final verification gate

- [ ] **Step 1: Full default gate**

```bash
composer validate --strict --no-check-publish && \
composer pint:test && \
composer larastan && \
composer rector:dry && \
composer security:racklab && \
composer openapi:check && \
composer audit && \
composer security:semgrep && \
composer pest:snapshots && \
composer i18n:missing && \
composer check-platform-reqs --no-interaction && \
npm audit --audit-level=high && \
npm run build && \
git diff --check && \
composer test
```

- [ ] **Step 2: Browser gate**

```bash
composer pest:browser
```

Expected: 9 passed.

- [ ] **Step 3: a11y gate**

```bash
APP_URL=http://127.0.0.1:8000 npm run a11y
```

Expected: 2/2 URLs, 0 errors.

- [ ] **Step 4: Final commit summary**

```bash
git log --oneline main..HEAD
```

Expected: 7–8 conventional-commit-prefixed commits (Horizon foundation, Quadlet refactor, image collapse, integration+browser, Dependabot, Grype, runner script, docs).

---

## Self-Review

**Spec coverage:** Every section of v2 maps to a task:
- §1 Install → Task 1
- §2 Supervisor topology (queues, pool group, retry_after) → Tasks 1, 2
- §3 Auth gate + permittedPlatform → Tasks 3, 6, 8, 9
- §4 Permission catalog → Task 4
- §5 Quadlet refactor (two containers) → Task 13
- §6 Audit nullability → Task 5
- §7 BindTenantContext Spatie fix → Task 11
- §8 Dependabot → Task 20
- §9 Grype two-scan → Task 22
- §10 Docs cleanup → Task 26
- §11 Self-hosted runner → Task 24
- Test plan (Tiny/Contract/Integration/Snapshot/Browser) → Tasks 2, 3, 5, 6, 7, 9, 10, 11, 13, 15, 17, 18, 20, 22, 24
- Codex review → Task 28
- Final verification → Task 29

**Placeholder scan:** No TBD/TODO. Each step has actual code or shell.

**Type consistency:** `HorizonAuthGate::authorize(?User $user)` used identically in Tasks 6, 8, 9, 18. `Permission` constructed via `new Permission('horizon.view')` matches verified signature. `AccessResolver::permittedPlatform()` signature: `(ActorIdentity, Permission): AccessDecision` — matches AccessResolver patterns. `RoleBindingScopeType::Global` verified enum case. Spatie `Tenant::makeCurrent()` / `Tenant::forgetCurrent()` verified in vendored code.

**Open questions for the executor:**
- Step 2 of Task 11: `App\Jobs\Test\AssertCurrentTenantJob` is a test-only job class — create it next to the test file.
- The exact `dispatch_sync()` vs `Bus::dispatchSync()` syntax depends on which is canonical in this repo; read existing job-dispatch tests to match.
- Task 13 Quadlet `Volume=/run/podman/podman.sock:...` — verify whether the existing socket mount style uses read-only (`:ro`) or read-write. The legacy `racklab-script-worker@.container` mount line is the reference.
- Task 15 `docker buildx imagetools create` requires authentication that the existing workflow already provides. If `--source-image` syntax differs in the actual Docker buildx version pinned, adjust to the working command.
- Task 26 PROGRESS.md edit is a long block insertion; preserve markdown rendering by checking output in Apostrophe before committing.
