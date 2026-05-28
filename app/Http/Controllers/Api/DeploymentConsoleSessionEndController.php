<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Auth\Jwt\ConsoleAccessGrantIssuer;
use App\Auth\Jwt\TrackAJwtClaims;
use App\Auth\Jwt\TrackAJwtRevoker;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\TokenGrant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeploymentConsoleSessionEndController extends Controller
{
    public function __invoke(
        Request $request,
        string $deployment,
        string $grant,
        TenantContextStore $tenantContext,
        TrackAJwtRevoker $revoker,
        AuditEventWriter $auditEvents,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $model = $this->deployment($deployment, $context);
        $tokenGrant = $this->grant($grant, $context, $model);
        $startedAt = $tokenGrant->created_at?->getTimestamp();
        $endedAt = now();
        $durationSeconds = $startedAt !== null ? max(0, $endedAt->getTimestamp() - $startedAt) : null;

        $revoker->revoke(
            jti: (string) $tokenGrant->jti,
            tenantId: $context->activeTenantId,
            revokedBy: $user,
            reason: 'console_session_end',
        );

        $auditEvents->append([
            'event_type' => 'console.session.end',
            'action' => 'end_session',
            'result' => 'allowed',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $model->resourceType(),
            'resource_id' => $model->resourceId(),
            'resource_tenant' => $model->tenantId(),
            'target_tenant_set' => [$context->activeTenantId, $model->tenantId()],
            'effective_permissions' => [ConsoleAccessGrantIssuer::CONNECT_PERMISSION],
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'grant_id' => $tokenGrant->resourceId(),
                'jti' => $tokenGrant->jti,
                'duration_seconds' => $durationSeconds,
            ],
        ]);

        return response()->noContent();
    }

    private function deployment(string $deploymentId, TenantContext $context): Deployment
    {
        /** @var Deployment|null $deployment */
        $deployment = Deployment::query()->whereKey($deploymentId)->first();

        if (! $deployment instanceof Deployment || $deployment->tenantId() !== $context->activeTenantId) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        return $deployment;
    }

    private function grant(string $grantId, TenantContext $context, Deployment $deployment): TokenGrant
    {
        /** @var TokenGrant|null $grant */
        $grant = TokenGrant::query()
            ->whereKey($grantId)
            ->where('tenant_id', $context->activeTenantId)
            ->where('track', 'jwt')
            ->where('resource_type', $deployment->resourceType())
            ->where('resource_id', $deployment->resourceId())
            ->first();

        if (! $grant instanceof TokenGrant) {
            throw new NotFoundHttpException('Console grant not found.');
        }

        if ($grant->revoked_at !== null) {
            throw new AuthorizationException('Console grant already revoked.');
        }

        // Ensure the grant is the console kind issued by ConsoleAccessGrantIssuer.
        if (! in_array(ConsoleAccessGrantIssuer::CONNECT_PERMISSION, (array) ($grant->abilities ?? []), strict: true)) {
            throw new NotFoundHttpException('Console grant not found.');
        }

        // Ensure the actor owns the grant — defense in depth against id guessing.
        $user = request()->user();
        if ($user instanceof User && $grant->owner_user_id !== $user->id) {
            throw new AuthorizationException('You may only end your own console grants.');
        }

        // Cross-check token-type via the request claims if the caller authenticated
        // with a Track A JWT (which they shouldn't — same self-refresh rule as
        // DeploymentConsoleGrantController).
        $claims = request()->attributes->get(TrackAJwtClaims::REQUEST_ATTRIBUTE);
        if ($claims instanceof TrackAJwtClaims && $claims->tokenType === ConsoleAccessGrantIssuer::TOKEN_TYPE) {
            throw new AuthorizationException('Console grants cannot be used to end console sessions.');
        }

        return $grant;
    }
}
