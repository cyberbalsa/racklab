<?php

declare(strict_types=1);

use App\Courses\CourseRosterImporter;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Course;
use App\Models\CourseMembership;
use App\Models\PendingCourseEnrollment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{Tenant, Course}
 */
function seedRosterCourse(): array
{
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    app(TenantContextStore::class)->set(new TenantContext(activeTenantId: $tenant->getKey()));
    $tenant->makeCurrent();

    /** @var Course $course */
    $course = Course::query()->create([
        'tenant_id' => $tenant->getKey(), 'name' => 'Net', 'slug' => 'net',
        'description' => null, 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);

    return [$tenant, $course];
}

it('enrols existing users and reports missing accounts in sign-in-only mode', function (): void {
    [$tenant, $course] = seedRosterCourse();
    User::factory()->create(['email' => 'has-account@example.test']);

    $result = app(CourseRosterImporter::class)->import(
        $course,
        $tenant->getKey(),
        "has-account@example.test\nno-account@example.test\n",
        ssoEnabled: false,
    );

    expect($result->enrolled)->toBe(1)
        ->and($result->missing)->toBe(['no-account@example.test'])
        ->and($result->pending)->toBe([])
        ->and(CourseMembership::query()->where('course_id', $course->getKey())->count())->toBe(1)
        ->and(PendingCourseEnrollment::query()->count())->toBe(0);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('records pending enrolments for unknown emails when SSO is enabled', function (): void {
    [$tenant, $course] = seedRosterCourse();
    User::factory()->create(['email' => 'has-account@example.test']);

    $result = app(CourseRosterImporter::class)->import(
        $course,
        $tenant->getKey(),
        "has-account@example.test\nfuture-sso@example.test\n",
        ssoEnabled: true,
    );

    expect($result->enrolled)->toBe(1)
        ->and($result->pending)->toBe(['future-sso@example.test'])
        ->and($result->missing)->toBe([])
        ->and(PendingCourseEnrollment::query()
            ->where('course_id', $course->getKey())
            ->where('email', 'future-sso@example.test')
            ->exists())->toBeTrue();

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});

it('is idempotent and does not duplicate memberships', function (): void {
    [$tenant, $course] = seedRosterCourse();
    User::factory()->create(['email' => 'has-account@example.test']);

    $importer = app(CourseRosterImporter::class);
    $importer->import($course, $tenant->getKey(), 'has-account@example.test', ssoEnabled: false);

    $second = $importer->import($course, $tenant->getKey(), 'Has-Account@example.test', ssoEnabled: false);

    expect($second->enrolled)->toBe(0)
        ->and($second->alreadyEnrolled)->toBe(1)
        ->and(CourseMembership::query()->where('course_id', $course->getKey())->count())->toBe(1);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
