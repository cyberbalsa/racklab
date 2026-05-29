<?php

declare(strict_types=1);

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Course;
use App\Models\CourseMembership;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a tenant with a course, an instructor + a student enrolled, a
 * course-associated deployment owned by the student, the student's personal
 * (non-course) deployment, and an unrelated non-member deployment.
 *
 * @return array{Tenant, User, User, Deployment, Deployment, Deployment}
 */
function seedCourseDeploymentFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $instructor = User::factory()->create(['name' => 'Instructor']);
    $student = User::factory()->create(['name' => 'Student']);
    $stranger = User::factory()->create(['name' => 'Stranger']);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $course = Course::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Networking',
        'slug' => 'networking',
        'description' => null,
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    CourseMembership::query()->create(['tenant_id' => $tenant->getKey(), 'course_id' => $course->getKey(), 'user_id' => $instructor->id, 'role' => 'instructor']);
    CourseMembership::query()->create(['tenant_id' => $tenant->getKey(), 'course_id' => $course->getKey(), 'user_id' => $student->id, 'role' => 'student']);

    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(), 'name' => 'P', 'slug' => 'p',
        'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(), 'name' => 'S', 'slug' => 's',
        'scope' => 'project_local', 'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => []], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);

    $make = static fn (User $owner, string $name, ?string $courseId): Deployment => Deployment::query()->create([
        'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(), 'course_id' => $courseId, 'stack_definition_id' => $stack->getKey(),
        'requested_by_id' => $owner->id, 'name' => $name, 'state' => 'running', 'provider' => 'fake',
        'metadata' => [], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);

    $courseDeployment = $make($student, 'course-vm', $course->getKey());
    $personalDeployment = $make($student, 'personal-vm', null);
    $strangerDeployment = $make($stranger, 'stranger-vm', null);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $instructor, $student, $courseDeployment, $personalDeployment, $strangerDeployment];
}

function permits(User $actor, string $permission, Deployment $deployment, Tenant $tenant): bool
{
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $allowed = app(AccessResolver::class)->permitted(
        new ActorIdentity((string) $actor->id),
        new Permission($permission),
        $deployment,
        $context,
    )->allowed;

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return $allowed;
}

it('lets a course instructor read and manage a deployment created for the course', function (): void {
    [$tenant, $instructor, , $courseDeployment] = seedCourseDeploymentFixture();

    expect(permits($instructor, 'deployment.read', $courseDeployment, $tenant))->toBeTrue()
        ->and(permits($instructor, 'deployment.power', $courseDeployment, $tenant))->toBeTrue();
});

it("does not let a course instructor read a member's personal (non-course) deployment", function (): void {
    [$tenant, $instructor, , , $personalDeployment] = seedCourseDeploymentFixture();

    // Over-grant guard: shared course membership alone must not expose a
    // member's deployments that were not created for the course.
    expect(permits($instructor, 'deployment.read', $personalDeployment, $tenant))->toBeFalse();
});

it('does not let a course instructor read a non-member, non-course deployment', function (): void {
    [$tenant, $instructor, , , , $strangerDeployment] = seedCourseDeploymentFixture();

    expect(permits($instructor, 'deployment.read', $strangerDeployment, $tenant))->toBeFalse();
});

it('does not let a student read a course deployment via the course', function (): void {
    [$tenant, , $student, $courseDeployment] = seedCourseDeploymentFixture();

    // A plain student member is not staff — no derived management access.
    expect(permits($student, 'deployment.read', $courseDeployment, $tenant))->toBeFalse();
});
