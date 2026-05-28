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
use App\Http\Requests\Api\UpdateScriptRequest;
use App\Models\Project;
use App\Models\Script;
use App\Models\ScriptApproval;
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
use JsonException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScriptUpdateController extends Controller
{
    /**
     * @throws JsonException
     */
    public function __invoke(
        UpdateScriptRequest $request,
        string $script,
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

        $model = $this->script($script);
        $project = $this->project($model);
        $permission = ScriptRunnerRegistry::createPermission($model->runner_kind);
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission($permission),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, $permission) || ! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to update this script.');
        }

        /** @var ScriptVersion|null $currentVersion */
        $currentVersion = $model->currentVersion;

        if (! $currentVersion instanceof ScriptVersion) {
            throw new RuntimeException('Script current version is missing.');
        }

        $nextCommand = $request->has('command')
            ? ScriptRunnerRegistry::normalizeCommand($request->input('command'))
            : $currentVersion->command;
        $nextSource = $request->has('source')
            ? $request->string('source')->toString()
            : $currentVersion->source;
        if ($request->has('source')) {
            $consoleScripts->assertValid($model->runner_kind, $nextSource);
            $ansiblePlaybooks->assertValid($model->runner_kind, $nextSource);
        }

        $nextHash = ScriptRunnerRegistry::executableHash($nextCommand, $nextSource);
        $executableChanged = $nextHash !== $currentVersion->executable_hash;

        $updated = DB::transaction(function () use (
            $request,
            $model,
            $context,
            $user,
            $currentVersion,
            $nextCommand,
            $nextSource,
            $nextHash,
            $executableChanged,
            $auditEvents,
            $permission,
        ): Script {
            $updates = [];

            if ($request->has('name')) {
                $updates['name'] = $request->string('name')->toString();
            }

            if ($request->has('metadata')) {
                $metadata = $request->input('metadata');
                $updates['metadata'] = is_array($metadata) ? $metadata : [];
            }

            if ($updates !== []) {
                $model->forceFill($updates)->save();
            }

            if ($executableChanged) {
                $versionNumber = $currentVersion->version_number + 1;

                /** @var ScriptVersion $version */
                $version = ScriptVersion::query()->create([
                    'tenant_id' => $context->activeTenantId,
                    'script_id' => $model->getKey(),
                    'created_by_id' => $user->getKey(),
                    'version_number' => $versionNumber,
                    'command' => $nextCommand,
                    'source' => $nextSource,
                    'executable_hash' => $nextHash,
                    'metadata' => [
                        'replaces_version_id' => $currentVersion->getKey(),
                    ],
                ]);

                $model->forceFill(['current_version_id' => $version->getKey()])->save();
                $invalidated = ScriptApproval::query()
                    ->where('script_id', $model->getKey())
                    ->where('state', 'active')
                    ->update([
                        'state' => 'invalidated',
                        'invalidated_at' => now(),
                        'invalidation_reason' => 'executable_changed',
                        'updated_at' => now(),
                    ]);

                if ($invalidated > 0) {
                    $this->audit($auditEvents, $request, $user, $context, $model, 'script.approval', 'invalidate', 'allowed', [
                        $permission,
                    ], [
                        'old_version_id' => $currentVersion->getKey(),
                        'new_version_id' => $version->getKey(),
                        'invalidated_count' => $invalidated,
                    ]);
                }
            }

            $this->audit($auditEvents, $request, $user, $context, $model, 'script.update', 'update', 'allowed', [$permission], [
                'executable_changed' => $executableChanged,
            ]);

            return $model->refresh();
        });

        return response()->json(['data' => ScriptPayload::make($updated)]);
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
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        AuditEventWriter $auditEvents,
        UpdateScriptRequest $request,
        User $actor,
        TenantContext $context,
        Script $script,
        string $eventType,
        string $action,
        string $result,
        array $permissions,
        array $metadata,
    ): void {
        $auditEvents->append([
            'event_type' => $eventType,
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $script->resourceType(),
            'resource_id' => $script->resourceId(),
            'resource_tenant' => $script->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => $permissions,
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
