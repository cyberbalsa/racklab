<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingRecord;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantScopedResource;
use App\Models\CourseMembership;
use App\Models\Deployment;

/**
 * Derives RBAC bindings from course staffing: a user who is `instructor`/`ta`
 * in a course may read and manage the deployments owned by members of that same
 * course. This is a relationship-based grant (course-staff → member-deployment)
 * expressed as a synthetic role binding so AccessResolver's existing three
 * predicates apply unchanged — the synthetic binding carries the actor's course
 * role (instructor/ta), is tenant-local to the deployment's tenant, and targets
 * the deployment resource.
 *
 * It only ever GRANTS to course staff over their own course's deployments:
 * a plain student member gets nothing here, cross-tenant courses never match
 * (membership is tenant-scoped and we pin the deployment tenant), and a
 * non-member's deployment is never covered.
 */
final readonly class CourseDeploymentAccess
{
    private const array MANAGING_ROLES = ['instructor', 'ta'];

    /**
     * @return list<RoleBindingRecord>
     */
    public function derivedBindings(ActorIdentity $actor, TenantScopedResource $resource): array
    {
        if (! $resource instanceof Deployment) {
            return [];
        }

        $ownerId = $resource->requested_by_id;

        if ($ownerId === null) {
            return [];
        }

        // Courses (in the deployment's tenant) the actor staffs, keyed by course
        // id → the actor's managing role there.
        $managed = CourseMembership::query()
            ->where('tenant_id', $resource->tenant_id)
            ->where('user_id', $actor->id)
            ->whereIn('role', self::MANAGING_ROLES)
            ->pluck('role', 'course_id');

        if ($managed->isEmpty()) {
            return [];
        }

        // Of those, the courses the deployment owner also belongs to.
        $ownerCourseIds = CourseMembership::query()
            ->where('tenant_id', $resource->tenant_id)
            ->where('user_id', $ownerId)
            ->whereIn('course_id', $managed->keys()->all())
            ->pluck('course_id')
            ->unique();

        $records = [];

        foreach ($ownerCourseIds as $courseId) {
            if (! is_string($courseId)) {
                continue;
            }

            $role = $managed->get($courseId);

            if (! is_string($role)) {
                continue;
            }

            $records[] = new RoleBindingRecord(
                id: 'course-staff:'.$courseId,
                principalId: $actor->id,
                role: $role,
                scopeType: RoleBindingScopeType::TenantLocal,
                tenantId: $resource->tenant_id,
                tenantSet: [],
                resourceType: $resource->resourceType(),
                resourceId: $resource->resourceId(),
            );
        }

        return $records;
    }
}
