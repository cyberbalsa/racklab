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
use App\Models\FloatingIp;
use App\Models\Project;
use App\Models\User;
use App\Networking\NetworkQuotaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FloatingIpReleaseController extends Controller
{
    private const string PERMISSION = 'network.allocate_public_ip';

    public function __invoke(
        Request $request,
        string $floatingIp,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        AuditEventWriter $auditEvents,
        NetworkQuotaService $networkQuota,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        /** @var FloatingIp|null $model */
        $model = FloatingIp::query()
            ->where('tenant_id', $context->activeTenantId)
            ->whereKey($floatingIp)
            ->first();

        if (! $model instanceof FloatingIp) {
            throw new NotFoundHttpException('Floating IP not found.');
        }

        /** @var Project|null $project */
        $project = Project::query()->whereKey($model->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(self::PERMISSION),
            $project,
            $context,
        );

        if (! $tokenAbilities->allows($request, self::PERMISSION) || ! $decision->allowed) {
            $this->audit($auditEvents, $user, $context, $project, 'release', 'denied', [
                'floating_ip_id' => $model->getKey(),
                'reason' => 'permission_not_granted',
            ]);

            throw new AuthorizationException('You are not allowed to release this floating IP.');
        }

        if ($model->state !== 'allocated') {
            throw ValidationException::withMessages([
                'floating_ip' => ['Only allocated floating IPs can be released.'],
            ]);
        }

        DB::transaction(function () use ($model, $user, $networkQuota, $auditEvents, $context, $project): void {
            $model->forceFill([
                'state' => 'released',
                'deployment_network_binding_id' => null,
                'released_at' => now(),
                'metadata' => [
                    ...($model->metadata ?? []),
                    'release_reason' => 'api.release',
                ],
            ])->save();

            $networkQuota->releaseForFloatingIp($model, $user);
            $this->audit($auditEvents, $user, $context, $project, 'release', 'allowed', [
                'floating_ip_id' => $model->getKey(),
                'address' => $model->address,
            ]);
        });

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        AuditEventWriter $auditEvents,
        User $user,
        TenantContext $context,
        Project $project,
        string $action,
        string $result,
        array $metadata,
    ): void {
        $auditEvents->append([
            'event_type' => 'network.floating_ip',
            'action' => $action,
            'result' => $result,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'project',
            'resource_id' => $project->getKey(),
            'resource_tenant' => $project->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => [self::PERMISSION],
            'metadata' => $metadata,
        ]);
    }
}
