<?php

declare(strict_types=1);

namespace App\Scripts;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\Project;
use App\Models\ScriptRun;
use App\Models\User;

final readonly class VisibleScriptRunList
{
    public function __construct(private AccessResolver $accessResolver) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, TenantContext $context, int $limit = 10): array
    {
        $actor = new ActorIdentity((string) $user->id);
        $permission = new Permission('project.read');
        $visible = [];

        /** @var ScriptRun $run */
        foreach (ScriptRun::query()->latest('created_at')->latest('id')->limit(50)->get() as $run) {
            if ($run->project_id === null) {
                continue;
            }

            /** @var Project|null $project */
            $project = Project::query()->whereKey($run->project_id)->first();

            if (! $project instanceof Project) {
                continue;
            }

            if (! $this->accessResolver->permitted($actor, $permission, $project, $context)->allowed) {
                continue;
            }

            $visible[] = ScriptPayload::run($run);

            if (count($visible) >= $limit) {
                break;
            }
        }

        return $visible;
    }
}
