<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

interface RoleBindingRepository
{
    /**
     * @return list<RoleBindingRecord>
     */
    public function forActorAndResource(ActorIdentity $actor, TenantScopedResource $resource): array;
}
