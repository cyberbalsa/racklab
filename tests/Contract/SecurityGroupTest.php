<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\QuotaLimit;
use App\Models\QuotaUsage;
use App\Models\RoleBinding;
use App\Models\SecurityGroup;
use App\Models\SecurityGroupRule;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates a security group with five fake-realized firewall rules and rule quota usage', function (): void {
    [$tenant, $user, $project] = provisionSecurityGroupProject();
    createSecurityGroupRuleQuota($tenant, $project, 5);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/security-groups', [
        'project_id' => $project->getKey(),
        'name' => 'Web SG',
        'slug' => 'web-sg',
        'rules' => fiveSecurityGroupRules(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'web-sg')
        ->assertJsonCount(5, 'data.rules')
        ->assertJsonPath('data.rules.0.provider_binding.mode', 'fake-firewall-rule');

    expect(SecurityGroup::query()->where('slug', 'web-sg')->exists())->toBeTrue()
        ->and(SecurityGroupRule::query()->count())->toBe(5)
        ->and(QuotaUsage::query()->where('dimension', 'security_group_rules')->where('state', 'active')->firstOrFail()->quantity)->toBe(5)
        ->and(AuditEvent::query()->where('event_type', 'network.security_group')->where('result', 'allowed')->exists())->toBeTrue();
});

it('updates security group rules and refreshes the fake firewall realization', function (): void {
    [$tenant, $user, $project] = provisionSecurityGroupProject();
    createSecurityGroupRuleQuota($tenant, $project, 5);

    Sanctum::actingAs($user);

    $securityGroupId = $this->postJson('/api/v1/security-groups', [
        'project_id' => $project->getKey(),
        'name' => 'Mutable SG',
        'slug' => 'mutable-sg',
        'rules' => fiveSecurityGroupRules(),
    ])->assertCreated()->json('data.id');

    expect($securityGroupId)->toBeString();

    $this->patchJson('/api/v1/security-groups/'.$securityGroupId, [
        'name' => 'Mutable SG Updated',
        'rules' => [
            [
                'direction' => 'ingress',
                'protocol' => 'tcp',
                'port_min' => 443,
                'port_max' => 443,
                'remote_cidr' => '0.0.0.0/0',
            ],
            [
                'direction' => 'egress',
                'protocol' => 'any',
                'remote_cidr' => '0.0.0.0/0',
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Mutable SG Updated')
        ->assertJsonCount(2, 'data.rules')
        ->assertJsonPath('data.rules.0.provider_binding.revision', 2);

    expect(SecurityGroupRule::query()->where('security_group_id', $securityGroupId)->count())->toBe(2)
        ->and(QuotaUsage::query()->where('dimension', 'security_group_rules')->where('state', 'active')->firstOrFail()->quantity)->toBe(2);
});

it('denies security group creation without manage permission and audits the denial', function (): void {
    [$tenant, $user, $project] = provisionSecurityGroupProject();

    RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $user->id)
        ->delete();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/security-groups', [
        'project_id' => $project->getKey(),
        'name' => 'Denied SG',
        'slug' => 'denied-sg',
        'rules' => [fiveSecurityGroupRules()[0]],
    ])->assertForbidden();

    expect(SecurityGroup::query()->count())->toBe(0)
        ->and(SecurityGroupRule::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'network.security_group')->where('result', 'denied')->exists())->toBeTrue();
});

it('denies security group creation when rule quota is exhausted', function (): void {
    [$tenant, $user, $project] = provisionSecurityGroupProject();
    createSecurityGroupRuleQuota($tenant, $project, 4);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/security-groups', [
        'project_id' => $project->getKey(),
        'name' => 'Quota Denied SG',
        'slug' => 'quota-denied-sg',
        'rules' => fiveSecurityGroupRules(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    expect(SecurityGroup::query()->count())->toBe(0)
        ->and(SecurityGroupRule::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'quota.denied')->where('action', 'network.security_group.create')->exists())->toBeTrue();
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionSecurityGroupProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create([
        'name' => 'Security Group Student',
        'email' => fake()->unique()->safeEmail(),
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function createSecurityGroupRuleQuota(Tenant $tenant, Project $project, int $limitValue): QuotaLimit
{
    /** @var QuotaLimit $limit */
    $limit = QuotaLimit::query()->create([
        'tenant_id' => $tenant->getKey(),
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
        'dimension' => 'security_group_rules',
        'limit_value' => $limitValue,
        'metadata' => [
            'source' => 'security-group-test',
        ],
    ]);

    return $limit;
}

/**
 * @return list<array<string, mixed>>
 */
function fiveSecurityGroupRules(): array
{
    return [
        ['direction' => 'ingress', 'protocol' => 'tcp', 'port_min' => 22, 'port_max' => 22, 'remote_cidr' => '10.0.0.0/8'],
        ['direction' => 'ingress', 'protocol' => 'tcp', 'port_min' => 80, 'port_max' => 80, 'remote_cidr' => '0.0.0.0/0'],
        ['direction' => 'ingress', 'protocol' => 'tcp', 'port_min' => 443, 'port_max' => 443, 'remote_cidr' => '0.0.0.0/0'],
        ['direction' => 'ingress', 'protocol' => 'icmp', 'remote_cidr' => '10.0.0.0/8'],
        ['direction' => 'egress', 'protocol' => 'any', 'remote_cidr' => '0.0.0.0/0'],
    ];
}
