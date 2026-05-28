<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Deployments\DeploymentPayload;
use App\Deployments\FakeDeploymentLifecycle;
use App\Deployments\ProxmoxDeploymentLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDeploymentOperationRequest;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeploymentOperationStoreController extends Controller
{
    public function __invoke(
        StoreDeploymentOperationRequest $request,
        string $deployment,
        TenantContextStore $tenantContext,
        FakeDeploymentLifecycle $deployments,
        ProxmoxDeploymentLifecycle $proxmoxDeployments,
        CurrentTokenAbilities $tokenAbilities,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        if (! $tokenAbilities->allows($request, 'deployment.update')) {
            throw new AuthorizationException('The current token does not include deployment.update.');
        }

        /** @var Deployment|null $model */
        $model = Deployment::query()->with('resources')->whereKey($deployment)->first();

        if (! $model instanceof Deployment) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        $kind = $request->string('kind')->toString();

        if ($model->provider === 'proxmox' && $kind === 'release') {
            $result = $proxmoxDeployments->operateRelease(
                actor: $user,
                context: $context,
                deployment: $model,
                idempotencyKey: $request->string('idempotency_key')->toString(),
                request: $request,
            );
        } elseif ($model->provider === 'proxmox' && in_array($kind, ['power_on', 'power_off'], true)) {
            $result = $proxmoxDeployments->operatePower(
                actor: $user,
                context: $context,
                deployment: $model,
                operationKind: $kind,
                deploymentResourceId: $request->string('deployment_resource_id')->toString() ?: null,
                idempotencyKey: $request->string('idempotency_key')->toString(),
                request: $request,
            );
        } else {
            $result = $deployments->operate(
                actor: $user,
                context: $context,
                deployment: $model,
                operationKind: $kind,
                deploymentResourceId: $request->string('deployment_resource_id')->toString() ?: null,
                idempotencyKey: $request->string('idempotency_key')->toString(),
                request: $request,
                simulateFailure: $request->boolean('simulate_failure'),
            );
        }

        return response()->json(
            ['data' => DeploymentPayload::make($result->deployment, $result->operation, $result->idempotentReplay)],
            $result->idempotentReplay ? 200 : 201,
        );
    }
}
