<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Course;
use App\Models\CourseMembership;
use App\Models\PendingCourseEnrollment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('converts a pending course enrolment to membership when the user first provisions', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    /** @var Course $course */
    $course = Course::query()->create([
        'tenant_id' => $tenant->getKey(), 'name' => 'Net', 'slug' => 'net',
        'description' => null, 'sharing_scope' => 'tenant_local', 'shared_with_tenants' => [],
    ]);

    PendingCourseEnrollment::query()->create([
        'tenant_id' => $tenant->getKey(),
        'course_id' => $course->getKey(),
        'email' => 'newcomer@example.test',
        'role' => 'student',
    ]);

    // The SSO user now logs in for the first time (case-insensitive email match).
    $user = User::factory()->create(['email' => 'Newcomer@example.test']);
    app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    expect(CourseMembership::query()
        ->where('course_id', $course->getKey())
        ->where('user_id', $user->id)
        ->where('role', 'student')
        ->exists())->toBeTrue()
        ->and(PendingCourseEnrollment::query()->where('email', 'newcomer@example.test')->exists())->toBeFalse();

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();
});
