<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Deployments\DefaultStackResolver;
use App\Deployments\DeploymentCreateResult;
use App\Deployments\FakeDeploymentLifecycle;
use App\Deployments\ProviderTaskReconciler;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProviderTask;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

final class OpsSmokeRackLab extends Command
{
    protected $signature = 'racklab:ops-smoke {--cycles=3 : Number of fake-provider worker restart cycles to run.} {--backup-dir= : Optional directory for per-cycle backup archives.} {--include-redis-backup : Include Redis logical dumps in per-cycle backup archives.}';

    protected $description = 'Run the RackLab Baseline operational smoke drill.';

    /**
     * @var list<string>
     */
    private array $deploymentIds = [];

    public function handle(
        RbacDefaultsSynchronizer $rbac,
        TenantContextStore $tenantContext,
        PersonalProjectProvisioner $projects,
        DefaultStackResolver $stacks,
        FakeDeploymentLifecycle $deployments,
        ProviderTaskReconciler $reconciler,
    ): int {
        $cycles = $this->cycles();
        $backupDir = $this->backupDir();
        $includeRedisBackup = $this->option('include-redis-backup') === true;
        $runId = strtolower((string) Str::ulid());

        $rbac->sync();

        $defaultTenantSlug = config('racklab.default_tenant_slug', 'default');
        $defaultTenantSlug = is_string($defaultTenantSlug) && trim($defaultTenantSlug) !== ''
            ? $defaultTenantSlug
            : 'default';

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => $defaultTenantSlug],
            ['name' => 'Default Tenant'],
        );
        $context = new TenantContext(activeTenantId: $tenant->id);
        $tenantContext->set($context);
        $tenant->makeCurrent();

        try {
            $user = $this->smokeUser($runId);
            $project = $projects->ensureFor($user, $context);
            $stack = $stacks->forProject($project);

            for ($cycle = 1; $cycle <= $cycles; $cycle++) {
                $this->runCycle(
                    cycle: $cycle,
                    runId: $runId,
                    user: $user,
                    context: $context,
                    project: $project,
                    stack: $stack,
                    deployments: $deployments,
                    reconciler: $reconciler,
                    backupDir: $backupDir,
                    includeRedisBackup: $includeRedisBackup,
                );
            }
        } finally {
            $tenantContext->forget();
            Tenant::forgetCurrent();
        }

        $stuck = ProviderTask::query()
            ->whereIn('deployment_id', $this->deploymentIds)
            ->whereIn('state', ['pending', 'running'])
            ->count();

        if ($stuck > 0) {
            $this->components->error(sprintf('RackLab ops smoke left %d provider task(s) pending or running.', $stuck));

            return self::FAILURE;
        }

        $this->components->info(sprintf('RackLab ops smoke passed %d cycle(s).', $cycles));

        return self::SUCCESS;
    }

    private function runCycle(
        int $cycle,
        string $runId,
        User $user,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        FakeDeploymentLifecycle $deployments,
        ProviderTaskReconciler $reconciler,
        ?string $backupDir,
        bool $includeRedisBackup,
    ): void {
        $result = $this->requestWithStoppedWorker($cycle, $runId, $user, $context, $project, $stack, $deployments);
        $this->deploymentIds[] = $result->deployment->id;

        /** @var ProviderTask $task */
        $task = ProviderTask::query()
            ->where('deployment_operation_id', $result->operation->getKey())
            ->firstOrFail();

        if ($task->state !== 'pending') {
            throw new RuntimeException(sprintf('Expected provider task [%s] to be pending before reconciliation.', $task->id));
        }

        $task->forceFill(['updated_at' => now()->subMinutes(5)])->save();
        $reconciler->reconcile();

        /** @var ProviderTask $task */
        $task = ProviderTask::query()->whereKey($task->getKey())->firstOrFail();
        /** @var Deployment $deployment */
        $deployment = Deployment::query()->whereKey($result->deployment->getKey())->firstOrFail();

        if ($task->state !== 'complete' || $deployment->state !== 'running') {
            throw new RuntimeException(sprintf('Ops smoke cycle %d did not complete the fake-provider deployment.', $cycle));
        }

        if ($backupDir !== null) {
            $this->writeBackup($backupDir, $cycle, $includeRedisBackup);
        }
    }

    private function requestWithStoppedWorker(
        int $cycle,
        string $runId,
        User $user,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        FakeDeploymentLifecycle $deployments,
    ): DeploymentCreateResult {
        $originalQueue = config('queue.default');
        config(['queue.default' => 'null']);

        try {
            return $deployments->request(
                actor: $user,
                context: $context,
                project: $project,
                stack: $stack,
                operationKind: 'add_vm',
                idempotencyKey: sprintf('ops-smoke-%s-%03d', $runId, $cycle),
                request: Request::create(
                    uri: '/racklab/ops-smoke',
                    method: 'POST',
                    server: [
                        'REMOTE_ADDR' => '127.0.0.1',
                        'HTTP_USER_AGENT' => 'racklab-ops-smoke',
                    ],
                ),
            );
        } finally {
            config(['queue.default' => $originalQueue]);
        }
    }

    private function writeBackup(string $backupDir, int $cycle, bool $includeRedisBackup): void
    {
        $path = $backupDir.'/racklab-ops-smoke-cycle-'.str_pad((string) $cycle, 3, '0', STR_PAD_LEFT).'.zip';
        $options = ['--to' => $path];

        if ($includeRedisBackup) {
            $options['--include-redis'] = true;
        }

        $exitCode = Artisan::call('racklab:backup', $options);

        if ($exitCode !== self::SUCCESS) {
            throw new RuntimeException(sprintf('Ops smoke backup failed for cycle %d: %s', $cycle, trim(Artisan::output())));
        }
    }

    private function smokeUser(string $runId): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'RackLab Ops Smoke',
            'email' => sprintf('racklab-ops-smoke-%s@example.test', $runId),
            'password' => Str::random(32),
        ]);

        return $user;
    }

    private function cycles(): int
    {
        $cycles = $this->option('cycles');
        $cycles = is_numeric($cycles) ? (int) $cycles : 0;

        return max(1, $cycles);
    }

    private function backupDir(): ?string
    {
        $backupDir = $this->option('backup-dir');

        if (! is_string($backupDir) || trim($backupDir) === '') {
            return null;
        }

        $backupDir = rtrim($backupDir, '/');

        if (! is_dir($backupDir) && ! mkdir($backupDir, 0775, true) && ! is_dir($backupDir)) {
            throw new RuntimeException(sprintf('Unable to create ops-smoke backup directory [%s].', $backupDir));
        }

        return $backupDir;
    }
}
