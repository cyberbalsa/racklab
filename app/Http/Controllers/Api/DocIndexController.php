<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\Tokens\CurrentTokenAbilities;
use App\Docs\DocPayload;
use App\Docs\DocVisibilityPolicy;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Http\Controllers\Controller;
use App\Models\Doc;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DocIndexController extends Controller
{
    private const string PERMISSION = 'docs.view';

    public function __invoke(
        Request $request,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        CurrentTokenAbilities $tokenAbilities,
        DocVisibilityPolicy $visibility,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        // Codex M8 S2 P1 #4: previous behavior was a silent 200 with an
        // empty data array on missing token ability, hiding scope errors
        // from API consumers. Return 403 to match every other index
        // endpoint convention (Scripts/Projects/etc.).
        if (! $tokenAbilities->allows($request, self::PERMISSION)) {
            throw new AuthorizationException('Token is not authorized for docs.view.');
        }

        $query = Doc::query()
            ->where('tenant_id', $context->activeTenantId)
            ->with('currentVersion')
            ->orderByDesc('updated_at');

        $projectId = $request->string('project_id')->toString();
        if ($projectId !== '') {
            $query->where('project_id', $projectId);
        }

        $docs = $query->limit(200)->get();

        $actor = new ActorIdentity((string) $user->id);
        $permission = new Permission(self::PERMISSION);

        $projectIds = $docs->pluck('project_id')->filter()->unique()->all();
        /** @var Collection<string, Project> $projects */
        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->where('tenant_id', $context->activeTenantId)
            ->get()
            ->keyBy(static function (Project $p): string {
                $key = $p->getKey();
                if (! is_string($key) && ! is_int($key)) {
                    throw new RuntimeException('Project key must be int or string.');
                }

                return (string) $key;
            });

        // Per-row AccessResolver gating against the parent Project — see
        // DocShowController for the gate rationale. The 200-row cap above
        // is the safety valve; pagination lands with the search slice.
        $visible = $docs->filter(function (Doc $doc) use ($actor, $permission, $accessResolver, $context, $projects, $user, $visibility): bool {
            if ($doc->project_id === null) {
                return false;
            }

            $project = $projects->get((string) $doc->project_id);
            if (! $project instanceof Project) {
                return false;
            }

            if (! $accessResolver->permitted($actor, $permission, $project, $context)->allowed) {
                return false;
            }

            // Codex M8 S2 P1 #1: drafts hidden from non-owner / non-curator.
            return $visibility->canRead($user, $doc, $project, $context);
        });

        return response()->json([
            'data' => $visible->values()->map(static fn (Doc $doc): array => DocPayload::make($doc))->all(),
        ]);
    }
}
