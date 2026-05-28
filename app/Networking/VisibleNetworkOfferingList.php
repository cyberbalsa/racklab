<?php

declare(strict_types=1);

namespace App\Networking;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\NetworkOffering;
use App\Models\User;

/**
 * Lists the network offerings a member may read (AccessResolver `network.read`)
 * in the active tenant. Network offerings are tenant-shared consumable
 * infrastructure, so the tenant_member baseline role grants read access; this
 * powers the stack builder's network picker.
 */
final readonly class VisibleNetworkOfferingList
{
    public function __construct(private AccessResolver $accessResolver) {}

    /**
     * @return list<NetworkOffering>
     */
    public function forUser(User $user, TenantContext $context): array
    {
        $actor = new ActorIdentity((string) $user->id);
        $permission = new Permission('network.offering.read');
        $visible = [];

        /** @var NetworkOffering $offering */
        foreach (NetworkOffering::query()->orderBy('name')->orderBy('id')->get() as $offering) {
            if ($this->accessResolver->permitted($actor, $permission, $offering, $context)->allowed) {
                $visible[] = $offering;
            }
        }

        return $visible;
    }
}
