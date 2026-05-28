<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Auth\Tokens\CurrentTokenAbilities;
use App\Docs\DocPayload;
use App\Docs\DocVisibilityPolicy;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\Doc;
use App\Models\DocVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DocVersionIndexController extends Controller
{
    private const string PERMISSION = 'docs.view';

    public function __invoke(
        Request $request,
        Doc $doc,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
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

        // Gate against the parent Project (see DocUpdateController).
        $project = $this->project($doc);

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            // 404 on read-deny mirrors DocShowController; never leak existence.
            $this->auditDenied($auditEvents, $user, $context, $doc, $project, 'permission_or_token_scope');

            throw new NotFoundHttpException('Doc not found.');
        }

        if (! $visibility->canRead($user, $doc, $project, $context)) {
            $this->auditDenied($auditEvents, $user, $context, $doc, $project, 'draft_hidden');

            throw new NotFoundHttpException('Doc not found.');
        }

        $versions = $doc->versions()->get();

        return response()->json([
            'data' => $versions->map(static fn (DocVersion $version): array => DocPayload::version($version))->all(),
        ]);
    }

    private function project(Doc $doc): Project
    {
        /** @var Project|null $project */
        $project = $doc->project_id === null
            ? null
            : Project::query()->whereKey($doc->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Doc not found.');
        }

        return $project;
    }

    private function auditDenied(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        Doc $doc,
        Project $project,
        string $reason,
    ): void {
        $auditEvents->append([
            'event_type' => 'docs.page',
            'action' => 'read',
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
                'reason' => $reason,
                'project_id' => $project->getKey(),
            ],
        ]);
    }
}
