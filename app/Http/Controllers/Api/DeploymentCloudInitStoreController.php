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
use App\Http\Requests\Api\StoreDeploymentCloudInitRequest;
use App\Models\Deployment;
use App\Models\HostKeyPhoneHomeToken;
use App\Models\ProjectSshKey;
use App\Models\Script;
use App\Models\ScriptVersion;
use App\Models\User;
use App\Provisioning\CloudInitRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeploymentCloudInitStoreController extends Controller
{
    /**
     * @throws JsonException
     */
    public function __invoke(
        StoreDeploymentCloudInitRequest $request,
        string $deployment,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        CloudInitRenderer $renderer,
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

        $model = $this->deployment($deployment);
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('deployment.update'),
            $model,
            $context,
        );

        if (! $tokenAbilities->allows($request, 'deployment.update') || ! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to update provisioning for this deployment.');
        }

        $version = $this->cloudInitVersion($request->string('script_version_id')->toString(), $model);
        $sshKeys = $this->projectSshKeys($request->input('project_ssh_key_ids'), $model);
        $plainToken = Str::random(48);
        $phoneHomeUrl = $request->root().'/api/v1/provisioning/host-keys/'.$plainToken;
        $rendered = $renderer->render($version->source, $sshKeys, $phoneHomeUrl);
        $redacted = $renderer->redactPhoneHomeToken($rendered, $plainToken);

        $token = DB::transaction(function () use (
            $request,
            $context,
            $user,
            $model,
            $version,
            $sshKeys,
            $plainToken,
            $redacted,
            $auditEvents,
        ): HostKeyPhoneHomeToken {
            /** @var HostKeyPhoneHomeToken $token */
            $token = HostKeyPhoneHomeToken::query()->create([
                'tenant_id' => $context->activeTenantId,
                'deployment_id' => $model->getKey(),
                'deployment_resource_id' => $request->string('deployment_resource_id')->toString() ?: null,
                'created_by_id' => $user->getKey(),
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addHours(12),
                'metadata' => [
                    'script_version_id' => $version->getKey(),
                ],
            ]);

            $metadata = $model->metadata ?? [];
            $metadata['cloud_init'] = [
                'script_id' => $version->script_id,
                'script_version_id' => $version->getKey(),
                'project_ssh_key_ids' => array_map(
                    static fn (ProjectSshKey $key): string => $key->id,
                    $sshKeys,
                ),
                'host_key_phone_home_token_id' => $token->getKey(),
                'rendered_redacted' => $redacted,
            ];
            $model->forceFill(['metadata' => $metadata])->save();

            $auditEvents->append([
                'event_type' => 'cloud_init.render',
                'action' => 'attach',
                'result' => 'allowed',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'actor_tenant' => $context->activeTenantId,
                'resource_type' => $model->resourceType(),
                'resource_id' => $model->resourceId(),
                'resource_tenant' => $model->tenant_id,
                'target_tenant_set' => [$context->activeTenantId],
                'effective_permissions' => ['deployment.update'],
                'source_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'script_version_id' => $version->getKey(),
                    'project_ssh_key_count' => count($sshKeys),
                    'host_key_phone_home_token_id' => $token->getKey(),
                ],
            ]);

            return $token;
        });

        return response()->json([
            'data' => [
                'deployment_id' => $model->getKey(),
                'script_version_id' => $version->getKey(),
                'project_ssh_key_ids' => array_map(
                    static fn (ProjectSshKey $key): string => $key->id,
                    $sshKeys,
                ),
                'phone_home_url' => $phoneHomeUrl,
                'host_key_phone_home_token_id' => $token->getKey(),
                'rendered_cloud_init' => $rendered,
                'rendered_redacted' => $redacted,
            ],
        ]);
    }

    private function deployment(string $deploymentId): Deployment
    {
        /** @var Deployment|null $deployment */
        $deployment = Deployment::query()->whereKey($deploymentId)->first();

        if (! $deployment instanceof Deployment) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        return $deployment;
    }

    private function cloudInitVersion(string $scriptVersionId, Deployment $deployment): ScriptVersion
    {
        /** @var ScriptVersion|null $version */
        $version = ScriptVersion::query()->with('script')->whereKey($scriptVersionId)->first();

        if (! $version instanceof ScriptVersion || ! $version->script instanceof Script) {
            throw new NotFoundHttpException('Cloud-init script version not found.');
        }

        if ($version->script->runner_kind !== 'cloudinit' || $version->script->project_id !== $deployment->project_id) {
            throw ValidationException::withMessages([
                'script_version_id' => 'Script version must be a cloud-init script from the deployment project.',
            ]);
        }

        return $version;
    }

    /**
     * @return list<ProjectSshKey>
     */
    private function projectSshKeys(mixed $rawKeyIds, Deployment $deployment): array
    {
        if (! is_array($rawKeyIds) || $rawKeyIds === []) {
            return [];
        }

        $keyIds = array_values(array_filter($rawKeyIds, static fn (mixed $keyId): bool => is_string($keyId) && $keyId !== ''));

        /** @var list<ProjectSshKey> $keys */
        $keys = ProjectSshKey::query()
            ->where('project_id', $deployment->project_id)
            ->whereIn('id', $keyIds)
            ->get()
            ->all();

        if (count($keys) !== count(array_unique($keyIds))) {
            throw ValidationException::withMessages([
                'project_ssh_key_ids' => 'Every Project SSH key must belong to the deployment project.',
            ]);
        }

        return $keys;
    }
}
