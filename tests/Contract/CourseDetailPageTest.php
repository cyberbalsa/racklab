<?php

declare(strict_types=1);

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Livewire\Courses\CourseDetail;
use App\Models\Course;
use App\Models\CourseMembership;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\StackDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, User, Course}
 */
function provisionCourseDetail(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $instructor = User::factory()->create(['name' => 'Ada Instructor', 'email' => 'ada@example.test']);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    /** @var Course $course */
    $course = Course::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Intro to Networking',
        'slug' => 'intro-networking',
        'description' => 'Fall semester lab course.',
        'sharing_scope' => 'tenant_local',
        'shared_with_tenants' => [],
    ]);

    RoleBinding::query()->create([
        'principal_type' => 'user',
        'principal_id' => (string) $instructor->id,
        'role' => 'instructor',
        'resource_type' => 'course',
        'resource_id' => $course->resourceId(),
        'scope_type' => RoleBindingScopeType::TenantLocal,
        'tenant_id' => $tenant->getKey(),
        'tenant_set' => [$tenant->getKey()],
        'granted_by_id' => $instructor->getKey(),
        'granted_reason' => 'course instructor',
    ]);

    $student = User::factory()->create(['name' => 'Bob Student', 'email' => 'bob@example.test']);

    CourseMembership::query()->create([
        'tenant_id' => $tenant->getKey(),
        'course_id' => $course->getKey(),
        'user_id' => $instructor->id,
        'role' => 'instructor',
    ]);
    CourseMembership::query()->create([
        'tenant_id' => $tenant->getKey(),
        'course_id' => $course->getKey(),
        'user_id' => $student->id,
        'role' => 'student',
    ]);

    // A deployment owned by the student member — the instructor should see it
    // on the course page via the course-derived deployment grant.
    $project = Project::query()->create([
        'tenant_id' => $tenant->getKey(), 'name' => 'Lab', 'slug' => 'lab',
        'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);
    $stack = StackDefinition::query()->create([
        'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(), 'name' => 'S', 'slug' => 's',
        'scope' => 'project_local', 'is_reserved_default' => false,
        'definition' => ['provider' => 'fake', 'components' => []], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);
    Deployment::query()->create([
        'tenant_id' => $tenant->getKey(), 'project_id' => $project->getKey(), 'stack_definition_id' => $stack->getKey(),
        'requested_by_id' => $student->id, 'name' => 'student-lab-vm', 'state' => 'running', 'provider' => 'fake',
        'metadata' => [], 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $instructor, $course];
}

it('shows an instructor their course with the roster', function (): void {
    [, $instructor, $course] = provisionCourseDetail();

    $this->actingAs($instructor)
        ->get('/courses/'.$course->getKey())
        ->assertOk()
        ->assertSee('Intro to Networking')
        ->assertSee('Ada Instructor')
        ->assertSee('Bob Student')
        ->assertSee('bob@example.test')
        // the instructor sees the student member's deployment (course grant)
        ->assertSee('student-lab-vm');
});

it('lets an instructor bulk-enrol registered users from the roster importer', function (): void {
    [$tenant, $instructor, $course] = provisionCourseDetail();
    $newcomer = User::factory()->create(['email' => 'carol@example.test']);

    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();
    test()->actingAs($instructor);

    Livewire::test(CourseDetail::class, ['course' => $course->getKey()])
        ->set('rosterInput', "carol@example.test\nghost@example.test\n")
        ->call('importRoster')
        ->assertHasNoErrors()
        ->assertSee('No account')        // sign-in-only mode reports the ghost
        ->assertSee('ghost@example.test');

    expect(CourseMembership::query()
        ->where('course_id', $course->getKey())
        ->where('user_id', $newcomer->id)
        ->exists())->toBeTrue();

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('returns 404 for a user who cannot read the course', function (): void {
    [$tenant, , $course] = provisionCourseDetail();

    app(RbacDefaultsSynchronizer::class)->sync();
    $outsider = User::factory()->create(['name' => 'Outsider']);

    $this->actingAs($outsider)
        ->get('/courses/'.$course->getKey())
        ->assertNotFound();
});
