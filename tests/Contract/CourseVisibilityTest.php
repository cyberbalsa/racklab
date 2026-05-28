<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Course;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns no courses for a user without course bindings', function (): void {
    [, $user] = provisionCourseVisibilityUser('student@example.test');
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/courses')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns courses readable through an instructor course binding', function (): void {
    [$tenant, $instructor] = provisionCourseVisibilityUser('instructor@example.test');
    $course = createCourseForVisibility($tenant);

    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $instructor->id,
        'role' => 'instructor',
        'resource_type' => 'course',
        'resource_id' => $course->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [],
        'granted_by_id' => $instructor->id,
        'granted_reason' => 'course instructor test binding',
    ]);

    Sanctum::actingAs($instructor);

    $this->getJson('/api/v1/courses')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $course->getKey())
        ->assertJsonPath('data.0.name', 'Intro to Systems');
});

it('does not expose another course to an unbound user in the same tenant', function (): void {
    [$tenant, $instructor] = provisionCourseVisibilityUser('bound-instructor@example.test');
    [, $student] = provisionCourseVisibilityUser('unbound-student@example.test', $tenant);
    $course = createCourseForVisibility($tenant);

    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $instructor->id,
        'role' => 'instructor',
        'resource_type' => 'course',
        'resource_id' => $course->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [],
        'granted_by_id' => $instructor->id,
        'granted_reason' => 'course instructor test binding',
    ]);

    Sanctum::actingAs($student);

    $this->getJson('/api/v1/courses')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/**
 * @return array{Tenant, User}
 */
function provisionCourseVisibilityUser(string $email, ?Tenant $tenant = null): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant ??= Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['email' => $email]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user];
}

function createCourseForVisibility(Tenant $tenant): Course
{
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    /** @var Course $course */
    $course = Course::query()->create([
        'name' => 'Intro to Systems',
        'slug' => 'intro-systems',
        'description' => 'Course visibility test',
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return $course;
}
