<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Deployments\DeploymentPayload;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
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

final class DeploymentShowController extends Controller
{
    public function __invoke(
        Request $request,
        string $deployment,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
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

        /** @var Deployment|null $model */
        $model = Deployment::query()->with('resources')->whereKey($deployment)->first();

        if (! $model instanceof Deployment) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('deployment.read'),
            $model,
            $context,
        );

        if (! $decision->allowed) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        return response()->json(['data' => DeploymentPayload::make($model)]);
    }
}
