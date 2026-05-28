<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Deployments\FakeDeploymentLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeploymentReleaseController extends Controller
{
    public function __invoke(
        Request $request,
        string $deployment,
        TenantContextStore $tenantContext,
        FakeDeploymentLifecycle $deployments,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        /** @var Deployment|null $model */
        $model = Deployment::query()->whereKey($deployment)->first();

        if (! $model instanceof Deployment) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        $deployments->operate(
            actor: $user,
            context: $context,
            deployment: $model,
            operationKind: 'release',
            deploymentResourceId: null,
            idempotencyKey: 'dashboard-release-'.Str::ulid()->toString(),
            request: $request,
        );

        return redirect()->route('dashboard');
    }
}
