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
use App\Models\Project;
use App\Models\ProviderDrift;
use App\Models\User;
use App\Networking\ProviderDriftPayload;
use App\Networking\ProviderDriftResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProviderDriftRepairController extends Controller
{
    private const string PERMISSION = 'network.attach_provider';

    public function __invoke(
        Request $request,
        string $providerDrift,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        ProviderDriftResolver $resolver,
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

        $drift = $this->drift($providerDrift, $context);
        $project = $this->project($drift);
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $this->auditDenied($auditEvents, $user, $context, $drift, 'repair');

            throw new AuthorizationException('You are not allowed to repair provider drift.');
        }

        return response()->json([
            'data' => ProviderDriftPayload::make($resolver->repair($drift, $user)),
        ]);
    }

    private function drift(string $providerDrift, TenantContext $context): ProviderDrift
    {
        /** @var ProviderDrift|null $drift */
        $drift = ProviderDrift::query()
            ->where('tenant_id', $context->activeTenantId)
            ->whereKey($providerDrift)
            ->first();

        if (! $drift instanceof ProviderDrift) {
            throw new NotFoundHttpException('Provider drift not found.');
        }

        return $drift;
    }

    private function project(ProviderDrift $drift): Project
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($drift->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Provider drift project not found.');
        }

        return $project;
    }

    private function auditDenied(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        ProviderDrift $drift,
        string $action,
    ): void {
        $auditEvents->append([
            'event_type' => 'provider.drift',
            'action' => $action,
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $drift->resource_type,
            'resource_id' => $drift->resource_id,
            'resource_tenant' => $drift->tenant_id,
            'target_tenant_set' => [$drift->tenant_id],
            'effective_permissions' => [self::PERMISSION],
            'metadata' => [
                'provider_drift_id' => $drift->getKey(),
                'reason' => 'permission_not_granted',
            ],
        ]);
    }
}
