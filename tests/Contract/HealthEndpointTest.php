<?php

declare(strict_types=1);

use App\Backup\RedisBackupClient;
use App\Backup\RedisBackupConnection;
use App\Backup\RedisConnectionConfig;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->artisan('migrate:fresh', ['--force' => true])
        ->assertExitCode(0);
});

it('reports liveness without authentication', function (): void {
    $this->get('/healthz')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
        ]);
});

it('reports readiness when the database connection is usable', function (): void {
    $this->get('/readyz')
        ->assertOk()
        ->assertJson([
            'status' => 'ready',
            'checks' => [
                'database' => 'ok',
                'schema' => 'ok',
            ],
        ]);
});

it('reports not-ready when the database check fails', function (): void {
    $defaultConnection = config('database.default');

    config(['database.default' => 'racklab_missing_connection']);
    DB::purge();

    try {
        $response = $this->get('/readyz');
    } finally {
        config(['database.default' => $defaultConnection]);
        DB::purge();
    }

    $response->assertStatus(503)
        ->assertJson([
            'status' => 'not_ready',
            'checks' => [
                'database' => 'failed',
            ],
        ]);
});

it('reports not-ready when the database is reachable but not migrated', function (): void {
    $databasePath = sys_get_temp_dir().'/racklab-ready-empty-'.bin2hex(random_bytes(6)).'.sqlite';
    touch($databasePath);

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
    ]);
    DB::purge();

    try {
        $this->get('/readyz')
            ->assertStatus(503)
            ->assertJson([
                'status' => 'not_ready',
                'checks' => [
                    'database' => 'ok',
                    'schema' => 'failed',
                ],
            ]);
    } finally {
        if (file_exists($databasePath)) {
            unlink($databasePath);
        }
    }
});

it('checks Redis readiness when a Redis-backed queue is configured', function (): void {
    $connection = new class implements RedisBackupConnection
    {
        /**
         * @param  list<string>  $arguments
         */
        public function command(array $arguments): mixed
        {
            return $arguments === ['PING'] ? 'PONG' : null;
        }

        public function close(): void {}
    };

    $this->app->instance(RedisBackupClient::class, new readonly class($connection) implements RedisBackupClient
    {
        public function __construct(private RedisBackupConnection $connection) {}

        public function connect(RedisConnectionConfig $connection): RedisBackupConnection
        {
            return $this->connection;
        }
    });

    config(['queue.default' => 'redis']);

    $this->get('/readyz')
        ->assertOk()
        ->assertJson([
            'status' => 'ready',
            'checks' => [
                'redis' => 'ok',
            ],
        ]);
});

it('reports not-ready when required Redis is unavailable', function (): void {
    $this->app->instance(RedisBackupClient::class, new class implements RedisBackupClient
    {
        public function connect(RedisConnectionConfig $connection): RedisBackupConnection
        {
            throw new RuntimeException('redis unavailable');
        }
    });

    config(['queue.default' => 'redis']);

    $this->get('/readyz')
        ->assertStatus(503)
        ->assertJson([
            'status' => 'not_ready',
            'checks' => [
                'database' => 'ok',
                'schema' => 'ok',
                'redis' => 'failed',
            ],
        ]);
});
