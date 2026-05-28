<?php

declare(strict_types=1);

use App\Audit\AuditEventWriter;
use App\Models\AuditEvent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('appends audit events with a tamper-evident hash chain', function (): void {
    $tenant = Tenant::query()->create(['name' => 'RIT', 'slug' => 'rit']);
    $writer = app(AuditEventWriter::class);

    $first = $writer->append([
        'event_type' => 'tenant.created',
        'action' => 'create',
        'result' => 'allowed',
        'actor_type' => 'system',
        'actor_id' => 'bootstrap',
        'actor_tenant' => $tenant->getKey(),
        'resource_type' => 'tenant',
        'resource_id' => $tenant->getKey(),
        'resource_tenant' => $tenant->getKey(),
        'target_tenant_set' => [],
        'metadata' => ['slug' => 'rit'],
    ]);
    $second = $writer->append([
        'event_type' => 'tenant.updated',
        'action' => 'update',
        'result' => 'allowed',
        'actor_type' => 'system',
        'actor_id' => 'bootstrap',
        'actor_tenant' => $tenant->getKey(),
        'resource_type' => 'tenant',
        'resource_id' => $tenant->getKey(),
        'resource_tenant' => $tenant->getKey(),
        'target_tenant_set' => [],
        'metadata' => ['name' => 'RIT Main'],
    ]);

    expect($first->prev_hash)->toBeNull()
        ->and($first->hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($second->prev_hash)->toBe($first->hash)
        ->and($second->hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($second->hash)->not->toBe($first->hash);
});

it('fails audit chain verification when an existing row is tampered with', function (): void {
    $tenant = Tenant::query()->create(['name' => 'RIT', 'slug' => 'rit']);
    $event = app(AuditEventWriter::class)->append([
        'event_type' => 'tenant.created',
        'action' => 'create',
        'result' => 'allowed',
        'actor_type' => 'system',
        'actor_id' => 'bootstrap',
        'actor_tenant' => $tenant->getKey(),
        'resource_type' => 'tenant',
        'resource_id' => $tenant->getKey(),
        'resource_tenant' => $tenant->getKey(),
        'target_tenant_set' => [],
        'metadata' => ['slug' => 'rit'],
    ]);

    AuditEvent::query()
        ->whereKey($event->getKey())
        ->update(['metadata' => ['slug' => 'changed-after-write']]);

    expect(Artisan::call('racklab:verify-audit-chain'))->toBe(1);
});
