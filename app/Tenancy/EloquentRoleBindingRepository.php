<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\RoleBindingRecord;
use App\Domain\Tenancy\RoleBindingRepository;
use App\Domain\Tenancy\TenantScopedResource;
use App\Models\RoleBinding;

final readonly class EloquentRoleBindingRepository implements RoleBindingRepository
{
    public function __construct(private CourseDeploymentAccess $courseDeploymentAccess) {}

    /**
     * @return list<RoleBindingRecord>
     */
    public function forActorAndResource(ActorIdentity $actor, TenantScopedResource $resource): array
    {
        $records = [];

        // Bindings that target the resource directly (e.g. a project-owner
        // binding).
        /** @var RoleBinding $binding */
        foreach (RoleBinding::query()
            ->where('principal_type', 'user')
            ->where('principal_id', $actor->id)
            ->where('resource_type', $resource->resourceType())
            ->where('resource_id', $resource->resourceId())
            ->get() as $binding) {
            $records[] = $binding->toRecord();
        }

        // Tenant-scoped membership bindings that target the resource's owning
        // tenant. A single tenant-membership binding therefore covers every
        // tenant-shared resource (the catalog) without a per-resource grant;
        // AccessResolver still enforces visibility and role-grants-permission.
        /** @var RoleBinding $binding */
        foreach (RoleBinding::query()
            ->where('principal_type', 'user')
            ->where('principal_id', $actor->id)
            ->where('resource_type', 'tenant')
            ->where('resource_id', $resource->tenantId())
            ->get() as $binding) {
            $records[] = $binding->toRecord();
        }

        // Course-staff → course-member-deployment grants (relationship-based,
        // synthesized as bindings on the deployment resource).
        foreach ($this->courseDeploymentAccess->derivedBindings($actor, $resource) as $derived) {
            $records[] = $derived;
        }

        return $records;
    }

    /**
     * @return list<RoleBindingRecord>
     */
    public function forActor(ActorIdentity $actor): array
    {
        $records = [];

        /** @var RoleBinding $binding */
        foreach (RoleBinding::query()
            ->where('principal_type', 'user')
            ->where('principal_id', $actor->id)
            ->get() as $binding) {
            $records[] = $binding->toRecord();
        }

        return $records;
    }
}
