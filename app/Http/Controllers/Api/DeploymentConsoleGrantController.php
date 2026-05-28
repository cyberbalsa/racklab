<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Audit\AuditEventWriter;
use App\Auth\Jwt\ConsoleAccessGrantIssuer;
use App\Auth\Jwt\TrackAJwtClaims;
use App\Auth\Tokens\CurrentTokenAbilities;
use App\Domain\Console\ConsoleKind;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDeploymentConsoleGrantRequest;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeploymentConsoleGrantController extends Controller
{
    public function __invoke(
        StoreDeploymentConsoleGrantRequest $request,
        string $deployment,
        TenantContextStore $tenantContext,
        ConsoleAccessGrantIssuer $issuer,
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

        $consoleKind = ConsoleKind::fromName($request->string('console_kind')->toString());
        $model = $this->deployment($deployment, $context);

        // Console grants are terminal credentials — they must not be usable to mint another
        // console grant. Otherwise a leaked grant could refresh itself indefinitely.
        $claims = $request->attributes->get(TrackAJwtClaims::REQUEST_ATTRIBUTE);
        if ($claims instanceof TrackAJwtClaims && $claims->tokenType === ConsoleAccessGrantIssuer::TOKEN_TYPE) {
            $this->auditTokenScopeDenial($auditEvents, $request, $user, $context, $model, $consoleKind, 'console_grant_self_refresh');

            throw new AuthorizationException('Console grants cannot be used to mint another console grant.');
        }

        if (! $tokenAbilities->allows($request, ConsoleAccessGrantIssuer::CONNECT_PERMISSION)) {
            $this->auditTokenScopeDenial($auditEvents, $request, $user, $context, $model, $consoleKind, 'token_missing_ability');

            throw new AuthorizationException(
                sprintf('The current token does not include %s.', ConsoleAccessGrantIssuer::CONNECT_PERMISSION)
            );
        }

        $issue = $issuer->issue(
            issuer: $user,
            context: $context,
            deployment: $model,
            consoleKind: $consoleKind,
        );

        $auditEvents->append([
            'event_type' => 'console.session.start',
            'action' => 'issue_grant',
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
                'grant_id' => $issue->grant->grantId,
                'jti' => $issue->grant->jti,
                'console_kind' => $consoleKind->value,
                'expires_at' => $issue->grant->expiresAt->toIso8601String(),
            ],
        ]);

        return response()->json([
            'data' => [
                'grant_id' => $issue->grant->grantId,
                'deployment_id' => $issue->grant->deploymentId,
                'console_kind' => $consoleKind->value,
                'jwt' => $issue->jwt,
                'kid' => $issue->kid,
                'expires_at' => $issue->grant->expiresAt->toIso8601String(),
            ],
        ]);
    }

    private function auditTokenScopeDenial(
        AuditEventWriter $auditEvents,
        Request $request,
        User $user,
        TenantContext $context,
        Deployment $deployment,
        ConsoleKind $consoleKind,
        string $reason,
    ): void {
        $auditEvents->append([
            'event_type' => 'console.access.denied',
            'action' => 'issue_grant',
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $deployment->resourceType(),
            'resource_id' => $deployment->resourceId(),
            'resource_tenant' => $deployment->tenantId(),
            'target_tenant_set' => [$context->activeTenantId, $deployment->tenantId()],
            'effective_permissions' => [ConsoleAccessGrantIssuer::CONNECT_PERMISSION],
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'console_kind' => $consoleKind->value,
                'reason' => $reason,
            ],
        ]);
    }

    private function deployment(string $deploymentId, TenantContext $context): Deployment
    {
        /** @var Deployment|null $deployment */
        $deployment = Deployment::query()->whereKey($deploymentId)->first();

        if (! $deployment instanceof Deployment) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        if ($deployment->tenantId() !== $context->activeTenantId) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        return $deployment;
    }
}
