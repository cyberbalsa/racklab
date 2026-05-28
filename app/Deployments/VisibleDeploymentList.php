<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\Deployment;
use App\Models\User;

final readonly class VisibleDeploymentList
{
    public function __construct(private AccessResolver $accessResolver) {}

    /**
     * @return list<Deployment>
     */
    public function forUser(User $user, TenantContext $context): array
    {
        $actor = new ActorIdentity((string) $user->id);
        $permission = new Permission('deployment.read');
        $visible = [];

        /** @var Deployment $deployment */
        foreach (Deployment::query()->with('resources.networkBindings.networkOffering')->latest('created_at')->latest('id')->get() as $deployment) {
            $decision = $this->accessResolver->permitted($actor, $permission, $deployment, $context);

            if ($decision->allowed) {
                $visible[] = $deployment;
            }
        }

        return $visible;
    }
}
