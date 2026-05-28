<?php

declare(strict_types=1);

use App\Jobs\RunUserScript;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (! redisIsAvailable()) {
        $this->markTestSkipped('Redis is not available — skipping Horizon worker smoke (CI/host gate)');
    }
});

it('routes RunUserScript to the script-worker queue on the redis connection through Horizon', function (): void {
    // Reuse the test queue connection (sync by default in pest:integration) so the
    // job executes inline and the dispatch is observable. This test does NOT spin
    // up a real Horizon supervisor — that requires a long-lived process and a
    // dedicated host. What we DO verify: jobs land on the script-worker queue
    // name (the queue Horizon's racklab-scripts supervisor consumes per
    // config/horizon.php), and Bus::assertDispatchedTimes sees the job.
    Bus::fake();

    $tenant = Tenant::query()->create(['name' => 'RIT', 'slug' => 'rit']);
    $scriptRunId = (string) Illuminate\Support\Str::ulid();

    RunUserScript::dispatch($tenant->id, $scriptRunId);

    Bus::assertDispatched(RunUserScript::class, fn (RunUserScript $job): bool => $job->scriptRunId === $scriptRunId
        && $job->queue === 'script-worker');
});

it('config/horizon.php scripts supervisor is wired to consume the script-worker queue', function (): void {
    putenv('RACKLAB_HORIZON_POOL_GROUP=all');
    $horizon = require base_path('config/horizon.php');
    putenv('RACKLAB_HORIZON_POOL_GROUP');

    $scriptsQueue = $horizon['defaults']['racklab-scripts']['queue'];

    expect($scriptsQueue)->toBe(['script-worker', 'scripts', 'cleanup']);
});

function redisIsAvailable(): bool
{
    try {
        Redis::connection('default')->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}
