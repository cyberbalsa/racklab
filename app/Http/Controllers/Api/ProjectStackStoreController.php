<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProjectStackRequest;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\User;
use App\Stacks\StackDefinitionPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectStackStoreController extends Controller
{
    public function __invoke(
        StoreProjectStackRequest $request,
        string $project,
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

        if (! $tokenAbilities->allows($request, 'project.update')) {
            throw new AuthorizationException('The current token does not include project.update.');
        }

        /** @var Project|null $model */
        $model = Project::query()->whereKey($project)->first();

        if (! $model instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('project.update'),
            $model,
            $context,
        );

        if (! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to create stacks in this project.');
        }

        $name = $request->string('name')->toString();
        $definition = $request->input('definition');

        /** @var StackDefinition $stack */
        $stack = StackDefinition::query()->create([
            'tenant_id' => $context->activeTenantId,
            'project_id' => $model->getKey(),
            'name' => $name,
            'slug' => $this->uniqueSlug($context->activeTenantId, $model->id, $name),
            'scope' => 'project_local',
            'is_reserved_default' => false,
            'definition' => is_array($definition) ? $definition : [],
            'sharing_scope' => 'tenant_local',
            'shared_with_tenants' => [],
        ]);

        return response()->json(['data' => StackDefinitionPayload::make($stack)], 201);
    }

    private function uniqueSlug(string $tenantId, string $projectId, string $name): string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'stack';
        }

        $candidate = $slug;
        $suffix = 1;

        while (StackDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('slug', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = $slug.'-'.$suffix;
        }

        return $candidate;
    }
}
