<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

interface RoleBindingRepository
{
    /**
     * @return list<RoleBindingRecord>
     */
    public function forActorAndResource(ActorIdentity $actor, TenantScopedResource $resource): array;

    /**
     * Return every role binding for the actor, regardless of resource.
     *
     * Used by `AccessResolver::permittedPlatform()` for platform-scope checks
     * where no `TenantScopedResource` exists. Implementations MUST NOT
     * pre-filter by resource type or tenant — the resolver does that filtering.
     *
     * @return list<RoleBindingRecord>
     */
    public function forActor(ActorIdentity $actor): array;
}
