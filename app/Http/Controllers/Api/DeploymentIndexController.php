<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Deployments\DeploymentPayload;
use App\Deployments\VisibleDeploymentList;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeploymentIndexController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContextStore $tenantContext,
        VisibleDeploymentList $deployments,
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

        if (! $tokenAbilities->allows($request, 'deployment.read')) {
            throw new AuthorizationException('The current token does not include deployment.read.');
        }

        return response()->json([
            'data' => array_map(
                static fn (Deployment $deployment): array => DeploymentPayload::make($deployment),
                $deployments->forUser($user, $context),
            ),
        ]);
    }
}
