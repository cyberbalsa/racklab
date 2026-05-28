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
use App\Http\Requests\Api\StoreProjectSshKeyRequest;
use App\Models\Project;
use App\Models\ProjectSshKey;
use App\Models\User;
use App\Provisioning\ProvisioningPayload;
use App\Provisioning\SshPublicKeyFingerprint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectSshKeyStoreController extends Controller
{
    public function __invoke(
        StoreProjectSshKeyRequest $request,
        string $project,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        SshPublicKeyFingerprint $fingerprints,
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

        $model = $this->project($project);
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('project.ssh_key.create'),
            $model,
            $context,
        );

        if (! $tokenAbilities->allows($request, 'project.ssh_key.create') || ! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to create SSH keys for this project.');
        }

        try {
            $details = $fingerprints->parse($request->string('public_key')->toString());
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw ValidationException::withMessages(['public_key' => $invalidArgumentException->getMessage()]);
        }

        /** @var ProjectSshKey $key */
        $key = ProjectSshKey::query()->create([
            'tenant_id' => $context->activeTenantId,
            'project_id' => $model->getKey(),
            'created_by_id' => $user->getKey(),
            'name' => $request->string('name')->toString(),
            'key_type' => $details->keyType,
            'public_key' => $details->publicKey,
            'fingerprint' => $details->fingerprint,
            'metadata' => $this->metadata($request->input('metadata')),
        ]);

        $auditEvents->append([
            'event_type' => 'project.ssh_key',
            'action' => 'create',
            'result' => 'allowed',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $model->resourceType(),
            'resource_id' => $model->resourceId(),
            'resource_tenant' => $model->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => ['project.ssh_key.create'],
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'project_ssh_key_id' => $key->getKey(),
                'fingerprint' => $key->fingerprint,
            ],
        ]);

        return response()->json(['data' => ProvisioningPayload::projectSshKey($key)], 201);
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
}
