<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Livewire\Scripts\ScriptLibrary;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Script;
use App\Models\ScriptApproval;
use App\Models\ScriptVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Project}
 */
function provisionScriptLibraryActor(string $name = 'Owner'): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->firstOrCreate(['slug' => 'default'], ['name' => 'Default Tenant']);
    $user = User::factory()->create(['name' => $name]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function seedProjectScript(Tenant $tenant, User $owner, Project $project, bool $approved, string $approvalScope = 'project'): Script
{
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();

    /** @var Script $script */
    $script = Script::query()->create([
        'tenant_id' => $tenant->getKey(),
        'project_id' => $project->getKey(),
        'owner_user_id' => $owner->id,
        'name' => 'Bootstrap Web',
        'slug' => 'bootstrap-web',
        'runner_kind' => 'ansible',
        'state' => 'active',
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
        'metadata' => [],
    ]);

    /** @var ScriptVersion $version */
    $version = ScriptVersion::query()->create([
        'tenant_id' => $tenant->getKey(),
        'script_id' => $script->getKey(),
        'created_by_id' => $owner->id,
        'version_number' => 1,
        'command' => ['ansible-playbook', 'site.yml'],
        'source' => "- hosts: all\n  tasks: []\n",
        'executable_hash' => hash('sha256', 'v1'),
        'metadata' => [],
    ]);

    $script->forceFill(['current_version_id' => $version->getKey()])->save();

    if ($approved) {
        ScriptApproval::query()->create([
            'tenant_id' => $tenant->getKey(),
            'script_id' => $script->getKey(),
            'script_version_id' => $version->getKey(),
            'approved_by_id' => $owner->id,
            'scope_type' => $approvalScope,
            'scope_id' => $project->getKey(),
            'state' => 'active',
        ]);
    }

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return $script;
}

it('lists a project script with its runner, version, and approval state', function (): void {
    [$tenant, $user, $project] = provisionScriptLibraryActor();
    seedProjectScript($tenant, $user, $project, approved: true);

    $this->actingAs($user)
        ->get('/projects/'.$project->getKey().'/scripts')
        ->assertOk()
        ->assertSee('Bootstrap Web')
        ->assertSee('ansible')
        ->assertSee('Approved')
        ->assertSee('v1');   // current version number
});

it('does not show an approval scoped to another resource as approved here', function (): void {
    [$tenant, $user, $project] = provisionScriptLibraryActor();
    // Active approval, but scoped to a deployment — not usable for a project run.
    seedProjectScript($tenant, $user, $project, approved: true, approvalScope: 'deployment');

    $this->actingAs($user)
        ->get('/projects/'.$project->getKey().'/scripts')
        ->assertOk()
        ->assertSee('Bootstrap Web')
        ->assertSee('Not approved');
});

it('marks a script without an active approval as unapproved', function (): void {
    [$tenant, $user, $project] = provisionScriptLibraryActor();
    seedProjectScript($tenant, $user, $project, approved: false);

    $this->actingAs($user)
        ->get('/projects/'.$project->getKey().'/scripts')
        ->assertOk()
        ->assertSee('Bootstrap Web')
        ->assertSee('Not approved');
});

it('lets an approver approve and revoke the current script version from the library', function (): void {
    [$tenant, $user, $project] = provisionScriptLibraryActor();
    $script = seedProjectScript($tenant, $user, $project, approved: false);

    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();
    test()->actingAs($user);

    // Approve: an active project-scoped approval is created + audited.
    Livewire::test(ScriptLibrary::class, ['project' => $project->getKey()])
        ->call('approve', $script->getKey())
        ->assertHasNoErrors();

    expect(ScriptApproval::query()
        ->where('script_id', $script->getKey())
        ->where('scope_type', 'project')
        ->where('scope_id', $project->getKey())
        ->where('state', 'active')
        ->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'script.approval')->where('action', 'approve')->exists())->toBeTrue();

    // Revoke: the active approval is invalidated.
    Livewire::test(ScriptLibrary::class, ['project' => $project->getKey()])
        ->call('revoke', $script->getKey())
        ->assertHasNoErrors();

    expect(ScriptApproval::query()
        ->where('script_id', $script->getKey())
        ->where('state', 'active')
        ->whereNull('invalidated_at')
        ->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', 'script.approval')->where('action', 'revoke')->exists())->toBeTrue();

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('returns 404 for a user who cannot read the project', function (): void {
    [$tenant, $owner, $project] = provisionScriptLibraryActor('Owner');
    seedProjectScript($tenant, $owner, $project, approved: true);

    [, $outsider] = provisionScriptLibraryActor('Outsider');

    $this->actingAs($outsider)
        ->get('/projects/'.$project->getKey().'/scripts')
        ->assertNotFound();
});
