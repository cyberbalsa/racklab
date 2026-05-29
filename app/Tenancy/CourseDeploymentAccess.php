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
 * in a course may read and manage the deployments **created for that course**
 * (`deployment.course_id`). This is a relationship-based grant expressed as a
 * synthetic role binding so AccessResolver's existing three predicates apply
 * unchanged — the synthetic binding carries the actor's course role
 * (instructor/ta), is tenant-local to the deployment's tenant, and targets the
 * deployment resource.
 *
 * The grant is scoped to the deployment's explicit course association, NOT the
 * owner's membership: a member's personal deployment, or a deployment for a
 * different course, is never covered (no over-grant). Plain student members get
 * nothing, and cross-tenant courses never match (membership is tenant-scoped
 * and we pin the deployment tenant).
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

        $courseId = $resource->course_id;

        if ($courseId === null) {
            return [];
        }

        // The actor's managing role (instructor/ta) in this deployment's course,
        // within the deployment's tenant.
        $role = CourseMembership::query()
            ->where('tenant_id', $resource->tenant_id)
            ->where('course_id', $courseId)
            ->where('user_id', $actor->id)
            ->whereIn('role', self::MANAGING_ROLES)
            ->value('role');

        if (! is_string($role)) {
            return [];
        }

        return [
            new RoleBindingRecord(
                id: 'course-staff:'.$courseId,
                principalId: $actor->id,
                role: $role,
                scopeType: RoleBindingScopeType::TenantLocal,
                tenantId: $resource->tenant_id,
                tenantSet: [],
                resourceType: $resource->resourceType(),
                resourceId: $resource->resourceId(),
            ),
        ];
    }
}
