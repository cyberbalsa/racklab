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
    /**
     * @return list<RoleBindingRecord>
     */
    public function forActorAndResource(ActorIdentity $actor, TenantScopedResource $resource): array
    {
        $records = [];

        /** @var RoleBinding $binding */
        foreach (RoleBinding::query()
            ->where('principal_type', 'user')
            ->where('principal_id', $actor->id)
            ->where('resource_type', $resource->resourceType())
            ->where('resource_id', $resource->resourceId())
            ->get() as $binding) {
            $records[] = $binding->toRecord();
        }

        return $records;
    }
}
