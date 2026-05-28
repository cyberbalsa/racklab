<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Console\ConsoleKind;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use ValueError;

final class DeploymentShowController extends Controller
{
    public function __invoke(
        Request $request,
        string $deployment,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
    ): View {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        /** @var Deployment|null $model */
        $model = Deployment::query()->whereKey($deployment)->first();

        if (! $model instanceof Deployment) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        // 404 (not 403) for actors who can't read this deployment: same shape
        // as the API show endpoint, so a same-tenant outsider can't probe the
        // existence of the deployment by guessing or copying an id.
        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('deployment.read'),
            $model,
            $context,
        );

        if (! $decision->allowed) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        return view('deployments.show', [
            'deployment' => $model,
            'consoleKind' => $this->resolveConsoleKind($model),
        ]);
    }

    private function resolveConsoleKind(Deployment $deployment): ConsoleKind
    {
        $metadata = $deployment->metadata ?? [];
        $explicit = $metadata['console_kind'] ?? null;

        if (is_string($explicit) && trim($explicit) !== '') {
            try {
                return ConsoleKind::fromName($explicit);
            } catch (ValueError) {
                // Fall through to provider-based heuristic on bad metadata.
            }
        }

        // LXC containers get the terminal pane; everything else (KVM VMs) gets noVNC.
        $kind = $deployment->resources()->orderBy('component_key')->value('kind');

        return $kind === 'lxc' ? ConsoleKind::Terminal : ConsoleKind::Vnc;
    }
}
