<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Course;
use App\Models\CourseMembership;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\QuotaEvent;
use App\Models\QuotaLimit;
use App\Models\QuotaReservation;
use App\Models\QuotaUsage;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('denies deployment creation when the project vcpu quota is exhausted', function (): void {
    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    createProjectQuotaLimit($tenant, $project, 'vcpu', 0);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-vcpu-denied',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    $auditEvent = AuditEvent::query()->where('event_type', 'quota.denied')->firstOrFail();

    expect(Deployment::query()->count())->toBe(0)
        ->and(QuotaReservation::query()->count())->toBe(0)
        ->and(QuotaEvent::query()->where('event_type', 'quota.denied')->where('result', 'denied')->exists())->toBeTrue()
        ->and($auditEvent->metadata['dimension'] ?? null)->toBe('vcpu')
        ->and($auditEvent->metadata['limit_value'] ?? null)->toBe(0);
});

it('counts reserved provider work so a second request cannot overcommit', function (): void {
    Queue::fake();
    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    createProjectQuotaLimit($tenant, $project, 'vcpu', 1);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-reserve-one',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending');

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-reserve-two',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    expect(QuotaReservation::query()->where('state', 'reserved')->count())->toBe(1)
        ->and(QuotaUsage::query()->where('state', 'active')->count())->toBe(0)
        ->and(QuotaEvent::query()->where('event_type', 'quota.denied')->where('dimension', 'vcpu')->exists())->toBeTrue();
});

it('enforces concurrent deployment reservations before provider work completes', function (): void {
    Queue::fake();
    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    createProjectQuotaLimit($tenant, $project, 'concurrent_deployments', 1);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'quota-concurrent-one',
    ])->assertCreated();

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'quota-concurrent-two',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    expect(QuotaReservation::query()->where('dimension', 'concurrent_deployments')->where('state', 'reserved')->count())->toBe(1)
        ->and(QuotaEvent::query()->where('event_type', 'quota.denied')->where('dimension', 'concurrent_deployments')->exists())->toBeTrue();
});

it('applies course-scoped quota limits to course members', function (): void {
    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    $course = createQuotaCourse($tenant, $user);
    createCourseQuotaLimit($tenant, $course, 'vcpu', 0);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-course-denied',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    $auditEvent = AuditEvent::query()->where('event_type', 'quota.denied')->firstOrFail();

    expect($auditEvent->metadata['scope_type'] ?? null)->toBe('course')
        ->and($auditEvent->metadata['scope_id'] ?? null)->toBe($course->getKey());
});

it('denies deployment creation when requested lease duration exceeds policy', function (): void {
    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    createProjectQuotaLimit($tenant, $project, 'lease_duration_minutes', 60);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-lease-too-long',
        'lease_duration_minutes' => 90,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lease_duration_minutes');

    $auditEvent = AuditEvent::query()->where('event_type', 'quota.denied')->firstOrFail();

    expect(Deployment::query()->count())->toBe(0)
        ->and(QuotaEvent::query()->where('event_type', 'quota.denied')->where('dimension', 'lease_duration_minutes')->exists())->toBeTrue()
        ->and($auditEvent->metadata['dimension'] ?? null)->toBe('lease_duration_minutes')
        ->and($auditEvent->metadata['requested'] ?? null)->toBe(90)
        ->and($auditEvent->metadata['limit_value'] ?? null)->toBe(60);
});

it('applies the most restrictive lease duration policy when duration is omitted', function (): void {
    $this->travelTo('2026-05-28 12:00:00');

    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    $course = createQuotaCourse($tenant, $user);
    createProjectQuotaLimit($tenant, $project, 'lease_duration_minutes', 120);
    createCourseQuotaLimit($tenant, $course, 'lease_duration_minutes', 45);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-lease-auto-cap',
    ])
        ->assertCreated()
        ->assertJsonPath('data.lease_expires_at', now()->addMinutes(45)->toJSON());

    $deployment = Deployment::query()->firstOrFail();

    expect($deployment->lease_expires_at?->timestamp)->toBe(now()->addMinutes(45)->timestamp)
        ->and($deployment->metadata['lease']['duration_minutes'] ?? null)->toBe(45)
        ->and($deployment->metadata['lease']['scope_type'] ?? null)->toBe('course')
        ->and($deployment->metadata['lease']['scope_id'] ?? null)->toBe($course->getKey());
});

it('enforces concurrent leased deployment quotas before provider work completes', function (): void {
    Queue::fake();
    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    createProjectQuotaLimit($tenant, $project, 'concurrent_leased_deployments', 1);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'quota-leased-concurrent-one',
        'lease_duration_minutes' => 60,
    ])->assertCreated();

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'deploy',
        'idempotency_key' => 'quota-leased-concurrent-two',
        'lease_duration_minutes' => 60,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    expect(QuotaReservation::query()->where('dimension', 'concurrent_leased_deployments')->where('state', 'reserved')->count())->toBe(1)
        ->and(QuotaEvent::query()->where('event_type', 'quota.denied')->where('dimension', 'concurrent_leased_deployments')->exists())->toBeTrue();
});

it('releases reserved quota when the provider operation fails', function (): void {
    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    createProjectQuotaLimit($tenant, $project, 'vcpu', 1);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-failing-vm',
        'simulate_failure' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'failed');

    expect(QuotaReservation::query()->firstOrFail()->state)->toBe('released')
        ->and(QuotaUsage::query()->where('state', 'active')->count())->toBe(0);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-after-failure',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'running');

    expect(QuotaUsage::query()->where('state', 'active')->count())->toBe(1);
});

it('converts reservations to usage and releases usage when deployment is released', function (): void {
    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    createProjectQuotaLimit($tenant, $project, 'vcpu', 1);

    Sanctum::actingAs($user);

    $deployment = $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-success-vm',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'running')
        ->json('data');

    expect(QuotaReservation::query()->where('state', 'consumed')->count())->toBe(1)
        ->and(QuotaUsage::query()->where('state', 'active')->count())->toBe(1);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-denied-after-use',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quota');

    $this->postJson('/api/v1/deployments/'.$deployment['id'].'/operations', [
        'kind' => 'release',
        'idempotency_key' => 'quota-release-vm',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'released');

    expect(QuotaUsage::query()->where('state', 'active')->count())->toBe(0)
        ->and(QuotaUsage::query()->where('state', 'released')->count())->toBe(1);
});

it('shows the most restrictive effective quota on the dashboard', function (): void {
    [$tenant, $user, $project] = provisionQuotaLifecycleUserProject();
    $course = createQuotaCourse($tenant, $user);
    createCourseQuotaLimit($tenant, $course, 'vcpu', 2);
    createProjectQuotaLimit($tenant, $project, 'vcpu', 5);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'quota-dashboard-vm',
    ])->assertCreated();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Quota')
        ->assertSee('vCPU')
        ->assertSee('1 / 2');
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionQuotaLifecycleUserProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Quota Student',
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

function createProjectQuotaLimit(Tenant $tenant, Project $project, string $dimension, int $limitValue): QuotaLimit
{
    /** @var QuotaLimit $limit */
    $limit = QuotaLimit::query()->create([
        'tenant_id' => $tenant->getKey(),
        'scope_type' => 'project',
        'scope_id' => $project->getKey(),
        'dimension' => $dimension,
        'limit_value' => $limitValue,
        'metadata' => [
            'source' => 'test',
        ],
    ]);

    return $limit;
}

function createQuotaCourse(Tenant $tenant, User $user): Course
{
    /** @var Course $course */
    $course = Course::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Quota Course',
        'slug' => 'quota-course',
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    CourseMembership::query()->create([
        'tenant_id' => $tenant->getKey(),
        'course_id' => $course->getKey(),
        'user_id' => $user->id,
        'role' => 'student',
    ]);

    return $course;
}

function createCourseQuotaLimit(Tenant $tenant, Course $course, string $dimension, int $limitValue): QuotaLimit
{
    /** @var QuotaLimit $limit */
    $limit = QuotaLimit::query()->create([
        'tenant_id' => $tenant->getKey(),
        'scope_type' => 'course',
        'scope_id' => $course->getKey(),
        'dimension' => $dimension,
        'limit_value' => $limitValue,
        'metadata' => [
            'source' => 'test',
        ],
    ]);

    return $limit;
}
