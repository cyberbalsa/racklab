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
 * Codex M8 S2 P1: draft/publish gating.
 *
 * Per PRD §22 RBAC text — `docs.publish` "mark[s] a document as
 * published (visible to all in scope)". The corollary is that
 * unpublished drafts are NOT visible to the general `docs.view`
 * audience. Without this gate, a student with `docs.view` on a
 * project could read another student's draft.
 *
 * Predicate: a doc is visible to an actor when ANY of:
 *   - it's published (`published_at !== null`), OR
 *   - the actor is the owner, OR
 *   - the actor holds `docs.publish` on the parent project
 *     (admins/instructors/support, who curate drafts as part of
 *     their role).
 *
 * The parent-Project AccessResolver call still gates `docs.view`
 * itself; this policy only narrows the published-vs-draft slice.
 * Cross-tenant sharing of docs lands via share-links (PRD §22),
 * deferred to M8 S4; v1 is tenant-local only.
 */
final readonly class DocVisibilityPolicy
{
    public function __construct(private AccessResolver $accessResolver) {}

    public function canRead(User $actor, Doc $doc, Project $project, TenantContext $context): bool
    {
        if ($doc->published_at !== null) {
            return true;
        }

        if ((int) $doc->owner_user_id === $actor->getKey()) {
            return true;
        }

        return $this->actorHoldsPublishPermission($actor, $project, $context);
    }

    public function canEdit(User $actor, Doc $doc, Project $project, TenantContext $context): bool
    {
        // Drafts are owner-only-editable unless the actor is a
        // doc curator (`docs.publish` holder). Published docs follow
        // the parent project's `docs.edit` permission via the
        // controller's existing AccessResolver call.
        if ($doc->published_at !== null) {
            return true;
        }

        if ((int) $doc->owner_user_id === $actor->getKey()) {
            return true;
        }

        return $this->actorHoldsPublishPermission($actor, $project, $context);
    }

    private function actorHoldsPublishPermission(User $actor, Project $project, TenantContext $context): bool
    {
        return $this->accessResolver
            ->permitted(
                new ActorIdentity((string) $actor->id),
                new Permission('docs.publish'),
                $project,
                $context,
            )
            ->allowed;
    }
}
