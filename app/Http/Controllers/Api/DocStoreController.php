<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Auth\Tokens\CurrentTokenAbilities;
use App\Docs\DocPayload;
use App\Docs\DocService;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDocRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DocStoreController extends Controller
{
    private const string PERMISSION = 'docs.create';

    public function __invoke(
        StoreDocRequest $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        DocService $docs,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $project = $this->project($request->string('project_id')->toString(), $context);

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $auditEvents->append([
                'event_type' => 'docs.page',
                'action' => 'create',
                'result' => 'denied',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => 'project',
                'resource_id' => $project->getKey(),
                'resource_tenant' => $project->tenant_id,
                'target_tenant_set' => [$context->activeTenantId],
                'effective_permissions' => [self::PERMISSION],
                'metadata' => [
                    'reason' => 'permission_not_granted',
                ],
            ]);

            throw new AuthorizationException('You are not allowed to create docs in this project.');
        }

        $doc = $docs->create(
            actor: $user,
            context: $context,
            title: $request->string('title')->toString(),
            markdown: $request->string('markdown')->toString(),
            project: $project,
            editorMessage: $request->input('editor_message') === null ? null : $request->string('editor_message')->toString(),
        );

        return response()->json(['data' => DocPayload::make($doc->refresh()->load('currentVersion'))], 201);
    }

    private function project(string $projectId, TenantContext $context): Project
    {
        /** @var Project|null $project */
        $project = Project::query()
            ->where('tenant_id', $context->activeTenantId)
            ->whereKey($projectId)
            ->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }
}
