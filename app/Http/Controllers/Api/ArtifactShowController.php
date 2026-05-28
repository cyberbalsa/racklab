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
use App\Models\Artifact;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ArtifactShowController extends Controller
{
    public function __invoke(
        Request $request,
        string $artifact,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        $model = $this->artifact($artifact);
        $this->authorizeArtifact($request, $model, $user, $context, $accessResolver, $tokenAbilities);

        if (! Storage::disk($model->storage_disk)->exists($model->storage_path)) {
            throw new NotFoundHttpException('Artifact content not found.');
        }

        return response(Storage::disk($model->storage_disk)->get($model->storage_path), 200, [
            'Content-Type' => $model->content_type,
            'X-RackLab-Artifact-Sha256' => $model->sha256,
        ]);
    }

    private function artifact(string $artifactId): Artifact
    {
        /** @var Artifact|null $artifact */
        $artifact = Artifact::query()->whereKey($artifactId)->first();

        if (! $artifact instanceof Artifact) {
            throw new NotFoundHttpException('Artifact not found.');
        }

        return $artifact;
    }

    private function authorizeArtifact(
        Request $request,
        Artifact $artifact,
        User $user,
        TenantContext $context,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
    ): void {
        if ($artifact->owner_scope_type === 'user' && $artifact->owner_scope_id === (string) $user->id) {
            return;
        }

        if ($artifact->owner_scope_type !== 'project') {
            throw new AuthorizationException('You are not allowed to read this artifact.');
        }

        if (! $tokenAbilities->allows($request, 'project.read')) {
            throw new AuthorizationException('The current token does not include project.read.');
        }

        /** @var Project|null $project */
        $project = Project::query()->whereKey($artifact->owner_scope_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Artifact owner project not found.');
        }

        $allowed = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('project.read'),
            $project,
            $context,
        );

        if (! $allowed->allowed) {
            throw new AuthorizationException('You are not allowed to read this artifact.');
        }
    }
}
