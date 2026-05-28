<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\ScriptRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('runs approved Ansible automation from the dashboard and downloads the result artifact', function (): void {
    Storage::fake('local');
    config(['racklab.container_runtime' => 'fake']);
    [$tenant, $user, $project] = provisionScriptBrowserProject();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Automation')
        ->assertSee('Run Ansible');

    $this->actingAs($user)
        ->post('/scripts/fake-runner', [
            'project_id' => $project->getKey(),
            'runner_kind' => 'ansible',
        ])
        ->assertRedirect('/dashboard');

    /** @var ScriptRun $run */
    $run = ScriptRun::query()
        ->where('tenant_id', $tenant->getKey())
        ->where('project_id', $project->getKey())
        ->where('runner_kind', 'ansible')
        ->firstOrFail();
    $artifact = Artifact::query()->whereKey($run->metadata['output_artifact_ids'][0])->firstOrFail();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('ansible')
        ->assertSee('succeeded')
        ->assertSee('ansible_result')
        ->assertSee($artifact->getKey());

    $this->actingAs($user)
        ->get('/artifacts/'.$artifact->getKey())
        ->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertSee('"runner":"ansible"', false);
});

it('runs approved console automation from the dashboard and downloads screenshot and serial artifacts', function (): void {
    Storage::fake('local');
    config(['racklab.container_runtime' => 'fake']);
    [$tenant, $user, $project] = provisionScriptBrowserProject(email: 'console-browser@example.test');

    $this->actingAs($user)
        ->post('/scripts/fake-runner', [
            'project_id' => $project->getKey(),
            'runner_kind' => 'console_script',
        ])
        ->assertRedirect('/dashboard');

    /** @var ScriptRun $run */
    $run = ScriptRun::query()
        ->where('tenant_id', $tenant->getKey())
        ->where('project_id', $project->getKey())
        ->where('runner_kind', 'console_script')
        ->firstOrFail();
    $artifactIds = $run->metadata['output_artifact_ids'];
    $artifacts = Artifact::query()->whereIn('id', $artifactIds)->orderBy('kind')->get();
    $screenshot = $artifacts->firstWhere('kind', 'script_screenshot');
    $serial = $artifacts->firstWhere('kind', 'script_serial');

    expect($screenshot)->toBeInstanceOf(Artifact::class)
        ->and($serial)->toBeInstanceOf(Artifact::class);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('console_script')
        ->assertSee('console_screenshot')
        ->assertSee('console_serial');

    $this->actingAs($user)
        ->get('/artifacts/'.$screenshot->getKey())
        ->assertOk()
        ->assertHeader('content-type', 'image/png')
        ->assertSee('fake screenshot shell-prompt', false);

    $this->actingAs($user)
        ->get('/artifacts/'.$serial->getKey())
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=utf-8')
        ->assertSee('wait_screen:$', false);
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionScriptBrowserProject(?string $email = null): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create([
        'name' => 'Script Browser Admin',
        'email' => $email ?? 'script-browser@example.test',
    ]);
    TenantMembership::query()->create([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->getKey(),
        'is_primary' => true,
    ]);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    /** @var Project $project */
    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Browser Script Project',
        'slug' => 'browser-script-project',
        'created_for_user_id' => $user->getKey(),
        'is_personal_default' => false,
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'role' => 'admin',
        'resource_type' => $project->resourceType(),
        'resource_id' => $project->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $user->getKey(),
        'granted_reason' => 'script browser test fixture',
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
