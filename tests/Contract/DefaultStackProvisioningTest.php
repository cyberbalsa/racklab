<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Identity\PersonalProjectProvisioner;
use App\Models\ProjectDefaultStack;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a reserved default stack definition for each personal project', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Mary Jackson']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    $provisioner = app(PersonalProjectProvisioner::class);

    $project = $provisioner->ensureFor($user, $context);
    $again = $provisioner->ensureFor($user, $context);

    $pointer = ProjectDefaultStack::query()->where('project_id', $project->getKey())->firstOrFail();
    $stack = StackDefinition::query()->whereKey($pointer->stack_definition_id)->firstOrFail();

    expect($again->is($project))->toBeTrue()
        ->and(ProjectDefaultStack::query()->where('project_id', $project->getKey())->count())->toBe(1)
        ->and(StackDefinition::query()->where('project_id', $project->getKey())->count())->toBe(1)
        ->and($pointer->tenant_id)->toBe($tenant->getKey())
        ->and($pointer->active_deployment_id)->toBeNull()
        ->and($stack->tenant_id)->toBe($tenant->getKey())
        ->and($stack->project_id)->toBe($project->getKey())
        ->and($stack->name)->toBe('Default')
        ->and($stack->slug)->toBe('default')
        ->and($stack->scope)->toBe('project_local')
        ->and($stack->is_reserved_default)->toBeTrue();
});
