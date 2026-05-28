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
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\User;
use App\Stacks\StackDefinitionPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectStackIndexController extends Controller
{
    public function __invoke(
        Request $request,
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

        if (! $tokenAbilities->allows($request, 'project.read')) {
            throw new AuthorizationException('The current token does not include project.read.');
        }

        /** @var Project|null $model */
        $model = Project::query()->whereKey($project)->first();

        if (! $model instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('project.read'),
            $model,
            $context,
        );

        if (! $decision->allowed) {
            throw new NotFoundHttpException('Project not found.');
        }

        $stacks = StackDefinition::query()
            ->where('project_id', $model->getKey())
            ->orderByDesc('is_reserved_default')
            ->orderBy('name')
            ->get()
            ->map(static fn (StackDefinition $stack): array => StackDefinitionPayload::make($stack))
            ->all();

        return response()->json(['data' => $stacks]);
    }
}
