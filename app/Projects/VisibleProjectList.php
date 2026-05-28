<?php

declare(strict_types=1);

namespace App\Projects;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\Project;
use App\Models\User;

final readonly class VisibleProjectList
{
    public function __construct(private AccessResolver $accessResolver) {}

    /**
     * @return list<Project>
     */
    public function forUser(User $user, TenantContext $context): array
    {
        $actor = new ActorIdentity((string) $user->id);
        $permission = new Permission('project.read');
        $visible = [];

        /** @var Project $project */
        foreach (Project::query()->orderBy('name')->orderBy('id')->get() as $project) {
            $decision = $this->accessResolver->permitted($actor, $permission, $project, $context);

            if ($decision->allowed) {
                $visible[] = $project;
            }
        }

        return $visible;
    }
}
