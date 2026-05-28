<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Auth\Tokens\CurrentTokenAbilities;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScriptApprovalRequest;
use App\Models\Project;
use App\Models\Script;
use App\Models\ScriptApproval;
use App\Models\ScriptVersion;
use App\Models\User;
use App\Scripts\ScriptPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScriptApprovalStoreController extends Controller
{
    public function __invoke(
        StoreScriptApprovalRequest $request,
        string $script,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $model = $this->script($script);
        $project = $this->project($model);
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('script.approve'),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, 'script.approve') || ! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to approve scripts in this project.');
        }

        $scopeType = $request->string('scope_type')->toString();
        $scopeId = $request->string('scope_id')->toString() ?: null;

        if ($scopeType === 'project' && $scopeId !== $model->project_id) {
            throw ValidationException::withMessages([
                'scope_id' => 'Project-scoped approvals must target the script project.',
            ]);
        }

        /** @var ScriptVersion|null $version */
        $version = $model->currentVersion;

        if (! $version instanceof ScriptVersion) {
            throw new NotFoundHttpException('Script version not found.');
        }

        /** @var ScriptApproval $approval */
        $approval = ScriptApproval::query()->create([
            'tenant_id' => $context->activeTenantId,
            'script_id' => $model->getKey(),
            'script_version_id' => $version->getKey(),
            'approved_by_id' => $user->getKey(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'state' => 'active',
            'metadata' => $this->metadata($request->input('metadata')),
        ]);

        $this->audit($auditEvents, $request, $user, $context, $model, 'approve', 'allowed', [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'script_version_id' => $version->getKey(),
        ]);

        return response()->json(['data' => ScriptPayload::approval($approval)], 201);
    }

    private function script(string $scriptId): Script
    {
        /** @var Script|null $script */
        $script = Script::query()->with('currentVersion')->whereKey($scriptId)->first();

        if (! $script instanceof Script) {
            throw new NotFoundHttpException('Script not found.');
        }

        return $script;
    }

    private function project(Script $script): Project
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($script->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        AuditEventWriter $auditEvents,
        StoreScriptApprovalRequest $request,
        User $actor,
        TenantContext $context,
        Script $script,
        string $action,
        string $result,
        array $metadata,
    ): void {
        $auditEvents->append([
            'event_type' => 'script.approval',
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $script->resourceType(),
            'resource_id' => $script->resourceId(),
            'resource_tenant' => $script->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => ['script.approve'],
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                ...$metadata,
                'project_id' => $script->project_id,
                'runner_kind' => $script->runner_kind,
            ],
        ]);
    }
}
