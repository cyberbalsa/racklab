<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Projects\VisibleProjectList;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectIndexController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContextStore $tenantContext,
        VisibleProjectList $projects,
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

        return response()->json([
            'data' => array_map(
                static fn (Project $project): array => [
                    'id' => $project->getKey(),
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'tenant_id' => $project->tenant_id,
                    'is_personal_default' => $project->is_personal_default,
                    'sharing_scope' => $project->sharing_scope,
                ],
                $projects->forUser($user, $context),
            ),
        ]);
    }
}
