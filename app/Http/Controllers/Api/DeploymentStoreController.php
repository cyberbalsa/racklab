<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Catalog\CatalogDeployer;
use App\Deployments\DeploymentPayload;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDeploymentRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeploymentStoreController extends Controller
{
    public function __invoke(
        StoreDeploymentRequest $request,
        TenantContextStore $tenantContext,
        CatalogDeployer $deployer,
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

        if (! $tokenAbilities->allows($request, 'deployment.create')) {
            throw new AuthorizationException('The current token does not include deployment.create.');
        }

        $projectId = $request->string('project_id')->toString();

        /** @var Project|null $project */
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $result = $deployer->deploy(
            user: $user,
            context: $context,
            project: $project,
            request: $request,
            operationKind: $request->string('operation')->toString() ?: 'deploy',
            idempotencyKey: $request->string('idempotency_key')->toString(),
            catalogVersionId: $request->string('catalog_version_id')->toString(),
            stackDefinitionId: $request->string('stack_definition_id')->toString(),
            simulateFailure: $request->boolean('simulate_failure'),
        );

        return response()->json(
            ['data' => DeploymentPayload::make($result->deployment, $result->operation, $result->idempotentReplay)],
            $result->idempotentReplay ? 200 : 201,
        );
    }
}
