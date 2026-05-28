<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('uses jsonb for the PostgreSQL audit tenant-set GIN index', function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL migration behavior requires DB_CONNECTION=pgsql.');
    }

    $this->artisan('migrate:fresh', ['--force' => true])
        ->assertExitCode(0);

    $column = DB::selectOne(<<<'SQL'
        select udt_name
        from information_schema.columns
        where table_schema = current_schema()
          and table_name = 'audit_events'
          and column_name = 'target_tenant_set'
        SQL);
    $index = DB::selectOne(<<<'SQL'
        select indexdef
        from pg_indexes
        where schemaname = current_schema()
          and tablename = 'audit_events'
          and indexname = 'audit_events_target_tenant_set_gin'
        SQL);

    expect($column?->udt_name)->toBe('jsonb')
        ->and($index?->indexdef)->toContain('USING gin');
});
