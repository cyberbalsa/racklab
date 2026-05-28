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
use App\Models\ProjectSshKey;
use App\Models\User;
use App\Provisioning\ProvisioningPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectSshKeyIndexController extends Controller
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

        $model = $this->project($project);
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('project.ssh_key.read'),
            $model,
            $context,
        );

        if (! $tokenAbilities->allows($request, 'project.ssh_key.read') || ! $decision->allowed) {
            throw new AuthorizationException('You are not allowed to read SSH keys for this project.');
        }

        return response()->json([
            'data' => ProjectSshKey::query()
                ->where('project_id', $model->getKey())
                ->orderBy('name')
                ->get()
                ->map(static fn (ProjectSshKey $key): array => ProvisioningPayload::projectSshKey($key))
                ->all(),
        ]);
    }

    private function project(string $projectId): Project
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }
}
