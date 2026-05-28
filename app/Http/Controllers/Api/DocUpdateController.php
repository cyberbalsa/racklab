<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Auth\Tokens\CurrentTokenAbilities;
use App\Docs\DocPayload;
use App\Docs\DocService;
use App\Docs\DocVisibilityPolicy;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateDocRequest;
use App\Models\Doc;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DocUpdateController extends Controller
{
    private const string PERMISSION = 'docs.edit';

    public function __invoke(
        UpdateDocRequest $request,
        Doc $doc,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        DocService $docs,
        DocVisibilityPolicy $visibility,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        if ($doc->tenant_id !== $context->activeTenantId) {
            throw new NotFoundHttpException('Doc not found.');
        }

        // Docs in v1 are project-scoped; we gate against the parent Project's
        // RoleBinding chain. The PersonalProjectProvisioner grants creators
        // an `admin` role binding on their personal project, which carries
        // the full `docs.*` permission set; finer-grained roles (TA,
        // student) restrict edit/publish per `DefaultRoleCatalog`.
        $project = $this->project($doc);

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $auditEvents->append([
                'event_type' => 'docs.page',
                'action' => 'update',
                'result' => 'denied',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => $doc->resourceType(),
                'resource_id' => $doc->resourceId(),
                'resource_tenant' => $doc->tenant_id,
                'target_tenant_set' => [$context->activeTenantId, $doc->tenant_id],
                'effective_permissions' => [self::PERMISSION],
                'metadata' => [
                    'reason' => 'permission_not_granted',
                    'project_id' => $project->getKey(),
                ],
            ]);

            throw new AuthorizationException('You are not allowed to edit this doc.');
        }

        // Codex M8 S2 P1 #1: draft-edit gate. Even with `docs.edit` on the
        // parent project, non-owners cannot edit another user's draft —
        // only owners + holders of `docs.publish` may.
        if (! $visibility->canEdit($user, $doc, $project, $context)) {
            $auditEvents->append([
                'event_type' => 'docs.page',
                'action' => 'update',
                'result' => 'denied',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => $doc->resourceType(),
                'resource_id' => $doc->resourceId(),
                'resource_tenant' => $doc->tenant_id,
                'target_tenant_set' => [$context->activeTenantId, $doc->tenant_id],
                'effective_permissions' => [self::PERMISSION],
                'metadata' => [
                    'reason' => 'draft_owner_only',
                    'project_id' => $project->getKey(),
                ],
            ]);

            throw new AuthorizationException('Only the owner can edit a draft doc.');
        }

        $updated = $docs->update(
            actor: $user,
            context: $context,
            doc: $doc,
            title: $request->string('title')->toString(),
            markdown: $request->string('markdown')->toString(),
            editorMessage: $request->input('editor_message') === null ? null : $request->string('editor_message')->toString(),
        );

        return response()->json(['data' => DocPayload::make($updated->refresh()->load('currentVersion'))]);
    }

    private function project(Doc $doc): Project
    {
        /** @var Project|null $project */
        $project = $doc->project_id === null
            ? null
            : Project::query()->whereKey($doc->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Doc has no resolvable project scope.');
        }

        return $project;
    }
}
