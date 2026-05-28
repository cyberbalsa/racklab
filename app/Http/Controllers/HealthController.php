<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Backup\RedisBackupClient;
use App\Backup\RedisConnectionConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class HealthController extends Controller
{
    public function liveness(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function readiness(RedisBackupClient $redis): JsonResponse
    {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (Throwable) {
            return response()->json([
                'status' => 'not_ready',
                'checks' => [
                    'database' => 'failed',
                ],
            ], 503);
        }

        $schemaReady = Schema::hasTable('migrations') && Schema::hasTable('tenants');
        $checks['schema'] = $schemaReady ? 'ok' : 'failed';

        if ($this->requiresRedis()) {
            $checks['redis'] = $this->redisReady($redis) ? 'ok' : 'failed';
        }

        if (in_array('failed', $checks, true)) {
            return response()->json([
                'status' => 'not_ready',
                'checks' => $checks,
            ], 503);
        }

        return response()->json([
            'status' => 'ready',
            'checks' => $checks,
        ]);
    }

    private function requiresRedis(): bool
    {
        if (config('racklab.health.redis_required') === true) {
            return true;
        }

        if (config('queue.default') === 'redis' || config('session.driver') === 'redis') {
            return true;
        }

        $cacheStore = config('cache.default');
        $cacheDriver = is_string($cacheStore) ? config('cache.stores.'.$cacheStore.'.driver') : null;

        return $cacheDriver === 'redis';
    }

    private function redisReady(RedisBackupClient $redis): bool
    {
        try {
            $connection = $redis->connect(RedisConnectionConfig::fromLaravelConfig(config('database.redis.default')));

            try {
                return $connection->command(['PING']) === 'PONG';
            } finally {
                $connection->close();
            }
        } catch (Throwable) {
            return false;
        }
    }
}
