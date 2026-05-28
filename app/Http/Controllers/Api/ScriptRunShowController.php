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
use App\Models\Script;
use App\Models\ScriptRun;
use App\Models\User;
use App\Scripts\ScriptPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScriptRunShowController extends Controller
{
    public function __invoke(
        Request $request,
        string $script,
        string $scriptRun,
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

        $model = $this->script($script);
        $run = $this->run($scriptRun, $model);
        $project = $this->project($model);
        $allowed = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('project.read'),
            $project,
            $context,
        );

        if (! $allowed->allowed) {
            throw new AuthorizationException('You are not allowed to read this script run.');
        }

        return response()->json(['data' => ScriptPayload::run($run)]);
    }

    private function script(string $scriptId): Script
    {
        /** @var Script|null $script */
        $script = Script::query()->whereKey($scriptId)->first();

        if (! $script instanceof Script) {
            throw new NotFoundHttpException('Script not found.');
        }

        return $script;
    }

    private function run(string $runId, Script $script): ScriptRun
    {
        /** @var ScriptRun|null $run */
        $run = ScriptRun::query()
            ->whereKey($runId)
            ->where('script_id', $script->getKey())
            ->first();

        if (! $run instanceof ScriptRun) {
            throw new NotFoundHttpException('Script run not found.');
        }

        return $run;
    }

    private function project(Script $script): Project
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($script->project_id)->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }
}
