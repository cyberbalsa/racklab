<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates and lists project-local stack definitions that can be deployed', function (): void {
    [, $user, $project] = provisionProjectStackUserProject();

    Sanctum::actingAs($user);

    $stack = $this->postJson('/api/v1/projects/'.$project->getKey().'/stacks', [
        'name' => 'Two host lab',
        'definition' => [
            'components' => [
                ['key' => 'router', 'kind' => 'vm'],
                ['key' => 'workstation', 'kind' => 'vm'],
            ],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Two host lab')
        ->assertJsonPath('data.scope', 'project_local')
        ->assertJsonPath('data.project_id', $project->getKey())
        ->json('data');

    expect($stack['id'])->toBeString()
        ->and(StackDefinition::query()->whereKey($stack['id'])->where('is_reserved_default', false)->exists())->toBeTrue();

    $this->getJson('/api/v1/projects/'.$project->getKey().'/stacks')
        ->assertOk()
        ->assertJsonFragment(['id' => $stack['id'], 'name' => 'Two host lab']);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'stack_definition_id' => $stack['id'],
        'operation' => 'deploy',
        'idempotency_key' => 'deploy-project-stack',
    ])
        ->assertCreated()
        ->assertJsonPath('data.stack_definition_id', $stack['id'])
        ->assertJsonPath('data.name', 'Two host lab')
        ->assertJsonPath('data.state', 'running');
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionProjectStackUserProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Stack Author']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
