<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Deployments\DefaultStackResolver;
use App\Deployments\FakeDeploymentLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeploymentNewVmController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContextStore $tenantContext,
        DefaultStackResolver $stacks,
        FakeDeploymentLifecycle $deployments,
    ): RedirectResponse {
        $request->validate([
            'project_id' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        /** @var Project|null $project */
        $project = Project::query()
            ->whereKey($request->string('project_id')->toString())
            ->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException('Project not found.');
        }

        $result = $deployments->request(
            actor: $user,
            context: $context,
            project: $project,
            stack: $stacks->forProject($project),
            operationKind: 'add_vm',
            idempotencyKey: 'dashboard-new-vm-'.Str::ulid()->toString(),
            request: $request,
        );

        return redirect()
            ->route('dashboard')
            ->with('deployment_id', $result->deployment->getKey());
    }
}
