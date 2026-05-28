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
use App\Http\Requests\Api\StoreScriptRequest;
use App\Models\Project;
use App\Models\Script;
use App\Models\ScriptVersion;
use App\Models\User;
use App\Scripts\AnsiblePlaybookValidator;
use App\Scripts\ConsoleScriptPrimitiveValidator;
use App\Scripts\ScriptPayload;
use App\Scripts\ScriptRunnerRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScriptStoreController extends Controller
{
    /**
     * @throws JsonException
     */
    public function __invoke(
        StoreScriptRequest $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        ConsoleScriptPrimitiveValidator $consoleScripts,
        AnsiblePlaybookValidator $ansiblePlaybooks,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $runnerKind = $request->string('runner_kind')->toString();
        $permission = ScriptRunnerRegistry::createPermission($runnerKind);
        $project = $this->project($request->string('project_id')->toString());
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission($permission),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, $permission) || ! $decision->allowed) {
            $this->audit($auditEvents, $request, $user, $context, $project, 'script.create', $runnerKind, 'denied', [$permission]);

            throw new AuthorizationException('You are not allowed to create this script runner kind.');
        }

        $command = ScriptRunnerRegistry::normalizeCommand($request->input('command'));
        $source = $request->string('source')->toString();
        $consoleScripts->assertValid($runnerKind, $source);
        $ansiblePlaybooks->assertValid($runnerKind, $source);
        $metadata = $this->metadata($request->input('metadata'));

        $script = DB::transaction(function () use (
            $request,
            $context,
            $user,
            $project,
            $runnerKind,
            $command,
            $source,
            $metadata,
            $auditEvents,
            $permission,
        ): Script {
            /** @var Script $script */
            $script = Script::query()->create([
                'tenant_id' => $context->activeTenantId,
                'project_id' => $project->resourceId(),
                'owner_user_id' => $user->getKey(),
                'name' => $request->string('name')->toString(),
                'slug' => $this->uniqueSlug($context->activeTenantId, $project->resourceId(), $request->string('name')->toString()),
                'runner_kind' => $runnerKind,
                'state' => 'draft',
                'sharing_scope' => 'tenant_local',
                'shared_with_tenants' => [],
                'metadata' => $metadata,
            ]);

            /** @var ScriptVersion $version */
            $version = ScriptVersion::query()->create([
                'tenant_id' => $context->activeTenantId,
                'script_id' => $script->getKey(),
                'created_by_id' => $user->getKey(),
                'version_number' => 1,
                'command' => $command,
                'source' => $source,
                'executable_hash' => ScriptRunnerRegistry::executableHash($command, $source),
                'metadata' => [],
            ]);

            $script->forceFill(['current_version_id' => $version->getKey()])->save();

            $this->audit($auditEvents, $request, $user, $context, $script, 'script.create', $runnerKind, 'allowed', [$permission]);

            return $script->refresh();
        });

        return response()->json(['data' => ScriptPayload::make($script)], 201);
    }

    private function project(string $projectId): Project
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($projectId)->first();

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

    private function uniqueSlug(string $tenantId, string $projectId, string $name): string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'script';
        }

        $candidate = $slug;
        $suffix = 1;

        while (Script::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('slug', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = $slug.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function audit(
        AuditEventWriter $auditEvents,
        StoreScriptRequest $request,
        User $actor,
        TenantContext $context,
        Project|Script $resource,
        string $eventType,
        string $action,
        string $result,
        array $permissions,
    ): void {
        $auditEvents->append([
            'event_type' => $eventType,
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $resource->resourceType(),
            'resource_id' => $resource->resourceId(),
            'resource_tenant' => $resource->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => $permissions,
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'project_id' => $resource instanceof Script ? $resource->project_id : $resource->getKey(),
                'runner_kind' => $action,
            ],
        ]);
    }
}
