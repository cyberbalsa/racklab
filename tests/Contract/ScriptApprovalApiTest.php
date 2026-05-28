<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\RunUserScript;
use App\Models\Artifact;
use App\Models\ArtifactReference;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\ScriptApproval;
use App\Models\ScriptRun;
use App\Models\ScriptVersion;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('denies advanced code script creation without the runner permission and audits the denial', function (): void {
    [$tenant, $student, $project] = provisionScriptApiProject(role: 'student');

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Needs review',
        'runner_kind' => 'advanced_code',
        'command' => ['python', 'main.py'],
        'source' => 'print("unsafe")',
    ])->assertForbidden();

    expect(AuditEvent::query()
        ->where('event_type', 'script.create')
        ->where('result', 'denied')
        ->where('actor_id', (string) $student->id)
        ->where('actor_tenant', $tenant->getKey())
        ->where('resource_id', $project->getKey())
        ->exists())->toBeTrue();
});

it('keeps approvals for metadata edits and invalidates them for executable edits', function (): void {
    [, $admin, $project] = provisionScriptApiProject(role: 'admin', email: 'script-admin@example.test');

    Sanctum::actingAs($admin);

    $script = $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Bootstrap host',
        'runner_kind' => 'advanced_code',
        'command' => ['python', 'main.py'],
        'source' => 'print("v1")',
        'metadata' => ['description' => 'first draft'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.current_version.version_number', 1)
        ->json('data');

    $approval = $this->postJson('/api/v1/scripts/'.$script['id'].'/approvals', [
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'active')
        ->json('data');

    $this->patchJson('/api/v1/scripts/'.$script['id'], [
        'metadata' => ['description' => 'renamed only'],
    ])
        ->assertOk()
        ->assertJsonPath('data.current_version.id', $script['current_version']['id']);

    expect(ScriptApproval::query()->whereKey($approval['id'])->firstOrFail()->state)->toBe('active');

    $updated = $this->patchJson('/api/v1/scripts/'.$script['id'], [
        'source' => 'print("v2")',
    ])
        ->assertOk()
        ->assertJsonPath('data.current_version.version_number', 2)
        ->json('data');

    expect($updated['current_version']['id'])->not->toBe($script['current_version']['id'])
        ->and(ScriptVersion::query()->where('script_id', $script['id'])->count())->toBe(2)
        ->and(ScriptApproval::query()->whereKey($approval['id'])->firstOrFail()->state)->toBe('invalidated')
        ->and(AuditEvent::query()->where('event_type', 'script.approval')->where('action', 'invalidate')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'script.update')->where('action', 'update')->exists())->toBeTrue();
});

it('queues approved advanced-code script runs and denies the same script after approval invalidation', function (): void {
    Queue::fake();

    [, $admin, $project] = provisionScriptApiProject(role: 'admin', email: 'run-approver@example.test');
    [, $student] = provisionScriptApiProjectMember($project, role: 'student', email: 'script-runner@example.test');

    Sanctum::actingAs($admin);

    $script = $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Approved runner',
        'runner_kind' => 'advanced_code',
        'command' => ['python', 'main.py'],
        'source' => 'print("approved")',
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/scripts/'.$script['id'].'/approvals', [
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
    ])->assertCreated();

    Sanctum::actingAs($student);

    $run = $this->postJson('/api/v1/scripts/'.$script['id'].'/runs', [])
        ->assertCreated()
        ->assertJsonPath('data.state', 'queued')
        ->assertJsonPath('data.runner_kind', 'advanced_code')
        ->json('data');

    expect(ScriptRun::query()->whereKey($run['id'])->where('script_id', $script['id'])->exists())->toBeTrue();

    Queue::assertPushed(
        RunUserScript::class,
        static fn (RunUserScript $job): bool => $job->scriptRunId === $run['id'],
    );

    Sanctum::actingAs($admin);
    $this->patchJson('/api/v1/scripts/'.$script['id'], [
        'command' => ['python', 'changed.py'],
    ])->assertOk();

    Sanctum::actingAs($student);
    $this->postJson('/api/v1/scripts/'.$script['id'].'/runs', [])
        ->assertForbidden();

    expect(AuditEvent::query()->where('event_type', 'script.run')->where('result', 'denied')->exists())->toBeTrue();
});

it('shows script runs with referenced log artifact metadata', function (): void {
    [, $admin, $project] = provisionScriptApiProject(role: 'admin', email: 'script-run-show@example.test');

    Sanctum::actingAs($admin);

    $script = $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Run with logs',
        'runner_kind' => 'advanced_code',
        'command' => ['python', 'main.py'],
        'source' => 'print("logs")',
    ])->assertCreated()->json('data');

    /** @var ScriptRun $run */
    $run = ScriptRun::query()->create([
        'tenant_id' => $project->tenant_id,
        'actor_user_id' => $admin->getKey(),
        'project_id' => $project->getKey(),
        'script_id' => $script['id'],
        'script_version_id' => $script['current_version']['id'],
        'runner_kind' => 'advanced_code',
        'state' => 'succeeded',
        'command' => ['python', 'main.py'],
        'source' => 'print("logs")',
        'exit_code' => 0,
        'metadata' => [],
    ]);
    /** @var Artifact $artifact */
    $artifact = Artifact::query()->create([
        'tenant_id' => $project->tenant_id,
        'kind' => 'script_log',
        'content_type' => 'text/plain; charset=utf-8',
        'size_bytes' => 12,
        'sha256' => str_repeat('a', 64),
        'storage_disk' => 'local',
        'storage_path' => 'artifacts/test.log',
        'quarantined' => true,
        'owner_scope_type' => 'project',
        'owner_scope_id' => $project->getKey(),
        'rbac_visibility' => 'actor_only',
        'metadata' => ['stream' => 'stdout'],
    ]);
    ArtifactReference::query()->create([
        'tenant_id' => $project->tenant_id,
        'artifact_id' => $artifact->getKey(),
        'reference_type' => ScriptRun::class,
        'reference_id' => $run->getKey(),
        'purpose' => 'script_stdout',
    ]);

    $this->getJson('/api/v1/scripts/'.$script['id'].'/runs/'.$run->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $run->getKey())
        ->assertJsonPath('data.artifacts.0.id', $artifact->getKey())
        ->assertJsonPath('data.artifacts.0.kind', 'script_log')
        ->assertJsonPath('data.artifacts.0.purpose', 'script_stdout')
        ->assertJsonMissingPath('data.artifacts.0.storage_path');
});

it('downloads project-owned script log artifacts through the API', function (): void {
    Storage::fake('local');
    [, $admin, $project] = provisionScriptApiProject(role: 'admin', email: 'artifact-download@example.test');
    Storage::disk('local')->put('artifacts/test-script.log', "script log\n");

    /** @var Artifact $artifact */
    $artifact = Artifact::query()->create([
        'tenant_id' => $project->tenant_id,
        'kind' => 'script_log',
        'content_type' => 'text/plain; charset=utf-8',
        'size_bytes' => strlen("script log\n"),
        'sha256' => hash('sha256', "script log\n"),
        'storage_disk' => 'local',
        'storage_path' => 'artifacts/test-script.log',
        'quarantined' => true,
        'owner_scope_type' => 'project',
        'owner_scope_id' => $project->getKey(),
        'rbac_visibility' => 'actor_only',
        'metadata' => ['stream' => 'stdout'],
    ]);

    Sanctum::actingAs($admin);

    $this->get('/api/v1/artifacts/'.$artifact->getKey())
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=utf-8')
        ->assertSeeText("script log\n");
});

it('validates console automation primitive source before creating openqa scripts', function (): void {
    [, $admin, $project] = provisionScriptApiProject(role: 'admin', email: 'console-script-validation@example.test');

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Console login',
        'runner_kind' => 'openqa',
        'command' => ['racklab-console', 'run'],
        'source' => json_encode([
            ['op' => 'type_string', 'text' => 'student'],
            ['op' => 'send_key', 'key' => 'ENTER'],
            ['op' => 'wait_screen', 'needle' => '$', 'timeout_seconds' => 30],
            ['op' => 'capture_screenshot', 'name' => 'shell-prompt'],
        ], JSON_THROW_ON_ERROR),
    ])
        ->assertCreated()
        ->assertJsonPath('data.runner_kind', 'openqa');

    $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Bad console script',
        'runner_kind' => 'openqa',
        'command' => ['racklab-console', 'run'],
        'source' => json_encode([
            ['op' => 'launch_provider_shell'],
        ], JSON_THROW_ON_ERROR),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');
});

it('validates Ansible playbook source and rejects ansible-galaxy runtime commands', function (): void {
    [, $admin, $project] = provisionScriptApiProject(role: 'admin', email: 'ansible-validation@example.test');

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Configure linux',
        'runner_kind' => 'ansible',
        'command' => ['ansible-playbook', 'site.yml'],
        'source' => <<<'YAML'
- hosts: all
  gather_facts: false
  tasks:
    - name: say hello
      ansible.builtin.debug:
        msg: hello
YAML,
    ])
        ->assertCreated()
        ->assertJsonPath('data.runner_kind', 'ansible');

    $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Install collections at runtime',
        'runner_kind' => 'ansible',
        'command' => ['ansible-playbook', 'site.yml'],
        'source' => <<<'YAML'
- hosts: all
  tasks:
    - name: fetch moving dependency
      ansible.builtin.command: ansible-galaxy collection install community.general
YAML,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');
});

it('creates approves runs inspects and downloads an Ansible artifact through the fake runtime', function (): void {
    Storage::fake('local');
    config(['racklab.container_runtime' => 'fake']);
    [, $admin, $project] = provisionScriptApiProject(role: 'admin', email: 'ansible-journey@example.test');

    Sanctum::actingAs($admin);

    $script = $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Fake Ansible Journey',
        'runner_kind' => 'ansible',
        'command' => ['ansible-playbook', 'site.yml'],
        'source' => <<<'YAML'
- hosts: all
  gather_facts: false
  tasks:
    - name: say hello
      ansible.builtin.debug:
        msg: hello
YAML,
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/scripts/'.$script['id'].'/approvals', [
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
    ])->assertCreated();

    $createdRun = $this->postJson('/api/v1/scripts/'.$script['id'].'/runs', [])
        ->assertCreated()
        ->json('data');

    $run = $this->getJson('/api/v1/scripts/'.$script['id'].'/runs/'.$createdRun['id'])
        ->assertOk()
        ->assertJsonPath('data.state', 'succeeded')
        ->assertJsonPath('data.exit_code', 0)
        ->assertJsonPath('data.metadata.runtime', 'fake')
        ->assertJsonPath('data.metadata.plays', 1)
        ->assertJsonPath('data.metadata.tasks', 1)
        ->json('data');
    $artifact = scriptRunArtifactByPurpose($run, 'ansible_result');

    $this->get('/api/v1/artifacts/'.$artifact['id'])
        ->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertSee('"runner":"ansible"', false);
});

it('creates approves runs inspects and downloads console artifacts through the fake runtime', function (): void {
    Storage::fake('local');
    config(['racklab.container_runtime' => 'fake']);
    [, $admin, $project] = provisionScriptApiProject(role: 'admin', email: 'console-journey@example.test');

    Sanctum::actingAs($admin);

    $script = $this->postJson('/api/v1/scripts', [
        'project_id' => $project->getKey(),
        'name' => 'Fake Console Journey',
        'runner_kind' => 'console_script',
        'command' => ['racklab-console', 'run'],
        'source' => json_encode([
            ['op' => 'type_string', 'text' => 'student'],
            ['op' => 'send_key', 'key' => 'ENTER'],
            ['op' => 'wait_screen', 'needle' => '$'],
            ['op' => 'capture_screenshot', 'name' => 'shell-prompt'],
            ['op' => 'capture_serial', 'name' => 'boot-log'],
        ], JSON_THROW_ON_ERROR),
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/scripts/'.$script['id'].'/approvals', [
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
    ])->assertCreated();

    $createdRun = $this->postJson('/api/v1/scripts/'.$script['id'].'/runs', [])
        ->assertCreated()
        ->json('data');

    $run = $this->getJson('/api/v1/scripts/'.$script['id'].'/runs/'.$createdRun['id'])
        ->assertOk()
        ->assertJsonPath('data.state', 'succeeded')
        ->assertJsonPath('data.metadata.runtime', 'fake')
        ->assertJsonPath('data.metadata.console_steps_executed', 5)
        ->json('data');
    $screenshot = scriptRunArtifactByPurpose($run, 'console_screenshot');
    $serial = scriptRunArtifactByPurpose($run, 'console_serial');

    $this->get('/api/v1/artifacts/'.$screenshot['id'])
        ->assertOk()
        ->assertHeader('content-type', 'image/png')
        ->assertSee('fake screenshot shell-prompt', false);

    $this->get('/api/v1/artifacts/'.$serial['id'])
        ->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=utf-8')
        ->assertSee('wait_screen:$', false);
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionScriptApiProject(string $role, ?string $email = null): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Script API '.$role,
        'email' => $email ?? fake()->unique()->safeEmail(),
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
        'name' => 'Script API Project',
        'slug' => 'script-api-project-'.strtolower($role).'-'.substr((string) $user->getKey(), -6),
        'created_for_user_id' => $user->getKey(),
        'is_personal_default' => false,
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    bindScriptApiProjectRole($tenant, $user, $project, $role);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

/**
 * @return array{Tenant, User}
 */
function provisionScriptApiProjectMember(Project $project, string $role, string $email): array
{
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->whereKey($project->tenant_id)->firstOrFail();
    $user = User::factory()->create([
        'name' => 'Script API '.$role,
        'email' => $email,
    ]);
    TenantMembership::query()->create([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $user->getKey(),
        'is_primary' => true,
    ]);

    bindScriptApiProjectRole($tenant, $user, $project, $role);

    return [$tenant, $user];
}

function bindScriptApiProjectRole(Tenant $tenant, User $user, Project $project, string $role): void
{
    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $user->id,
        'role' => $role,
        'resource_type' => $project->resourceType(),
        'resource_id' => $project->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $user->getKey(),
        'granted_reason' => 'script api test fixture',
    ]);
}

/**
 * @param  array<string, mixed>  $run
 * @return array<string, mixed>
 */
function scriptRunArtifactByPurpose(array $run, string $purpose): array
{
    $artifacts = $run['artifacts'] ?? [];

    if (! is_array($artifacts)) {
        throw new RuntimeException('Script run payload did not include artifacts.');
    }

    foreach ($artifacts as $artifact) {
        if (is_array($artifact) && ($artifact['purpose'] ?? null) === $purpose) {
            return $artifact;
        }
    }

    throw new RuntimeException(sprintf('Artifact purpose [%s] not found.', $purpose));
}
