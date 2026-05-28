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
use App\Http\Requests\Api\StoreScriptRunRequest;
use App\Jobs\RunAnsiblePlaybook;
use App\Jobs\RunConsoleScript;
use App\Jobs\RunUserScript;
use App\Models\Project;
use App\Models\Script;
use App\Models\ScriptApproval;
use App\Models\ScriptRun;
use App\Models\ScriptVersion;
use App\Models\User;
use App\Scripts\ScriptPayload;
use App\Scripts\ScriptRunnerRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScriptRunStoreController extends Controller
{
    public function __invoke(
        StoreScriptRunRequest $request,
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
        $version = $this->currentVersion($model);
        $actor = new ActorIdentity((string) $user->id);
        $projectRead = $accessResolver->permitted($actor, new Permission('project.read'), $project, $context);

        if (! $projectRead->allowed) {
            throw new AuthorizationException('You are not allowed to run scripts in this project.');
        }

        $approval = $this->activeApproval(
            script: $model,
            version: $version,
            projectId: $project->resourceId(),
            deploymentId: $request->string('deployment_id')->toString() ?: null,
        );
        $usingUnapprovedBypass = false;

        if (! $approval instanceof ScriptApproval) {
            $unapproved = $accessResolver->permitted($actor, new Permission('script.run_unapproved'), $project, $context);

            if (! $tokenAbilities->allows($request, 'script.run_unapproved') || ! $unapproved->allowed) {
                $this->audit($auditEvents, $request, $user, $context, $model, 'run', 'denied', [
                    'script_version_id' => $version->getKey(),
                    'reason' => 'approval_required',
                ]);

                throw new AuthorizationException('This script version is not approved for the requested scope.');
            }

            $usingUnapprovedBypass = true;
        }

        if (ScriptRunnerRegistry::jobClassFor($model->runner_kind) === null) {
            throw ValidationException::withMessages([
                'runner_kind' => 'This script runner cannot be dispatched as a container job.',
            ]);
        }

        /** @var ScriptRun $run */
        $run = ScriptRun::query()->create([
            'tenant_id' => $context->activeTenantId,
            'actor_user_id' => $user->getKey(),
            'project_id' => $project->resourceId(),
            'script_id' => $model->getKey(),
            'script_version_id' => $version->getKey(),
            'deployment_id' => $request->string('deployment_id')->toString() ?: null,
            'deployment_resource_id' => $request->string('deployment_resource_id')->toString() ?: null,
            'runner_kind' => $model->runner_kind,
            'state' => 'queued',
            'command' => $version->command,
            'source' => $version->source,
            'metadata' => [
                ...$this->metadata($request->input('metadata')),
                'approval_id' => $approval?->getKey(),
                'unapproved_bypass' => $usingUnapprovedBypass,
            ],
        ]);

        $this->dispatchRun($context->activeTenantId, $run->resourceId(), $model->runner_kind);
        $this->audit($auditEvents, $request, $user, $context, $model, 'run', 'allowed', [
            'script_version_id' => $version->getKey(),
            'script_run_id' => $run->getKey(),
            'approval_id' => $approval?->getKey(),
            'unapproved_bypass' => $usingUnapprovedBypass,
        ]);

        return response()->json(['data' => ScriptPayload::run($run)], 201);
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

    private function currentVersion(Script $script): ScriptVersion
    {
        /** @var ScriptVersion|null $version */
        $version = $script->currentVersion;

        if (! $version instanceof ScriptVersion) {
            throw new NotFoundHttpException('Script version not found.');
        }

        return $version;
    }

    private function activeApproval(
        Script $script,
        ScriptVersion $version,
        string $projectId,
        ?string $deploymentId,
    ): ?ScriptApproval {
        /** @var ScriptApproval|null $approval */
        $approval = ScriptApproval::query()
            ->where('script_id', $script->getKey())
            ->where('script_version_id', $version->getKey())
            ->where('state', 'active')
            ->where(function ($query) use ($projectId, $deploymentId): void {
                $query->where(function ($query) use ($projectId): void {
                    $query->where('scope_type', 'project')->where('scope_id', $projectId);
                })->orWhere(function ($query) use ($deploymentId): void {
                    if ($deploymentId === null) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query->where('scope_type', 'deployment')->where('scope_id', $deploymentId);
                })->orWhere('scope_type', 'catalog_script');
            })
            ->first();

        return $approval;
    }

    private function dispatchRun(string $tenantId, string $runId, string $runnerKind): void
    {
        match ($runnerKind) {
            'advanced_code', 'user_script' => RunUserScript::dispatch($tenantId, $runId),
            'network', 'ansible' => RunAnsiblePlaybook::dispatch($tenantId, $runId),
            'openqa', 'console_script' => RunConsoleScript::dispatch($tenantId, $runId),
            default => null,
        };
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
        StoreScriptRunRequest $request,
        User $actor,
        TenantContext $context,
        Script $script,
        string $action,
        string $result,
        array $metadata,
    ): void {
        $auditEvents->append([
            'event_type' => 'script.run',
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $script->resourceType(),
            'resource_id' => $script->resourceId(),
            'resource_tenant' => $script->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => ['project.read'],
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
