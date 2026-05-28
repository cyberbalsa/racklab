<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Deployments\DefaultStackResolver;
use App\Deployments\DeploymentPayload;
use App\Deployments\FakeDeploymentLifecycle;
use App\Deployments\ProxmoxDeploymentLifecycle;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDeploymentRequest;
use App\Models\CatalogItem;
use App\Models\CatalogVersion;
use App\Models\Project;
use App\Models\StackDefinition;
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
        DefaultStackResolver $stacks,
        FakeDeploymentLifecycle $deployments,
        ProxmoxDeploymentLifecycle $proxmoxDeployments,
        CurrentTokenAbilities $tokenAbilities,
        AccessResolver $accessResolver,
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

        $operation = $request->string('operation')->toString() ?: 'deploy';
        $projectId = $request->string('project_id')->toString();

        /** @var Project|null $project */
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $catalogVersionId = $request->string('catalog_version_id')->toString();
        $this->authorizeCatalogVersionRead($catalogVersionId, $user, $context, $accessResolver);
        $stack = $stacks->forProjectOrCatalogVersion(
            project: $project,
            stackDefinitionId: $request->string('stack_definition_id')->toString(),
            catalogVersionId: $catalogVersionId,
        );

        if ($this->stackProvider($stack) === 'proxmox') {
            $result = $proxmoxDeployments->request(
                actor: $user,
                context: $context,
                project: $project,
                stack: $stack,
                operationKind: $operation,
                idempotencyKey: $request->string('idempotency_key')->toString(),
                request: $request,
            );
        } else {
            $result = $deployments->request(
                actor: $user,
                context: $context,
                project: $project,
                stack: $stack,
                operationKind: $operation,
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

    private function authorizeCatalogVersionRead(
        string $catalogVersionId,
        User $user,
        TenantContext $context,
        AccessResolver $accessResolver,
    ): void {
        if ($catalogVersionId === '') {
            return;
        }

        /** @var CatalogVersion|null $version */
        $version = CatalogVersion::query()
            ->whereKey($catalogVersionId)
            ->where('state', 'published')
            ->first();

        if (! $version instanceof CatalogVersion) {
            throw new NotFoundHttpException('Catalog version not found.');
        }

        /** @var CatalogItem|null $item */
        $item = CatalogItem::query()->whereKey($version->catalog_item_id)->first();

        if (! $item instanceof CatalogItem) {
            throw new NotFoundHttpException('Catalog item not found.');
        }

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('catalog.read'),
            $item,
            $context,
        );

        if (! $decision->allowed) {
            throw new NotFoundHttpException('Catalog version not found.');
        }
    }

    private function stackProvider(StackDefinition $stack): string
    {
        $definition = $stack->definition ?? [];
        $provider = $definition['provider'] ?? null;

        return is_string($provider) ? $provider : 'fake';
    }
}
