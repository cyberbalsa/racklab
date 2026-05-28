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
    public function forUser(User $user, TenantContext $context, ?string $label = null, ?string $projectId = null): array
    {
        $actor = new ActorIdentity((string) $user->id);
        $permission = new Permission('deployment.read');
        $label = $label === null || trim($label) === '' ? null : mb_strtolower(trim($label));
        $visible = [];

        $query = Deployment::query()->with('resources.networkBindings.networkOffering');

        if ($projectId !== null && trim($projectId) !== '') {
            $query->where('project_id', $projectId);
        }

        /** @var Deployment $deployment */
        foreach ($query->latest('created_at')->latest('id')->get() as $deployment) {
            if ($label !== null && ! in_array($label, $deployment->labels ?? [], strict: true)) {
                continue;
            }

            $decision = $this->accessResolver->permitted($actor, $permission, $deployment, $context);

            if ($decision->allowed) {
                $visible[] = $deployment;
            }
        }

        return $visible;
    }
}
