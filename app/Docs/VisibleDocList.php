<?php

declare(strict_types=1);

namespace App\Docs;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Models\Doc;
use App\Models\Project;
use App\Models\User;

/**
 * Lists the docs an actor may read in the active tenant, mirroring
 * `VisibleProjectList`. A doc is included only when the parent Project
 * grants `docs.view` through `AccessResolver` AND the draft/publish
 * visibility policy admits the actor — so another user's unpublished
 * draft never appears. Per visible doc, the actor's `docs.edit`
 * capability is resolved for the index Edit affordance.
 */
final readonly class VisibleDocList
{
    public function __construct(
        private AccessResolver $accessResolver,
        private DocVisibilityPolicy $visibility,
    ) {}

    /**
     * @return list<VisibleDoc>
     */
    public function forUser(User $user, TenantContext $context): array
    {
        $actor = new ActorIdentity((string) $user->id);
        $viewPermission = new Permission('docs.view');
        $editPermission = new Permission('docs.edit');
        $visible = [];

        /** @var Doc $doc */
        foreach (Doc::query()->orderByDesc('updated_at')->orderBy('id')->get() as $doc) {
            $project = $this->project($doc);

            if (! $project instanceof Project) {
                continue;
            }

            if (! $this->accessResolver->permitted($actor, $viewPermission, $project, $context)->allowed) {
                continue;
            }

            if (! $this->visibility->canRead($user, $doc, $project, $context)) {
                continue;
            }

            $canEdit = $this->accessResolver->permitted($actor, $editPermission, $project, $context)->allowed
                && $this->visibility->canEdit($user, $doc, $project, $context);

            $visible[] = new VisibleDoc($doc, $canEdit);
        }

        return $visible;
    }

    private function project(Doc $doc): ?Project
    {
        if ($doc->project_id === null) {
            return null;
        }

        /** @var Project|null $project */
        $project = Project::query()->whereKey($doc->project_id)->first();

        return $project;
    }
}
