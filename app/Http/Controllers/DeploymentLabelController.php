<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Deployments\LabelNormalizer;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sets the owner-defined labels on a deployment so members can organize and
 * filter their own resources. Authorized by `deployment.update` on the
 * deployment through AccessResolver; denial returns a not-found so a same-tenant
 * outsider cannot probe or relabel another member's deployment.
 */
final class DeploymentLabelController extends Controller
{
    public function __invoke(
        Request $request,
        string $deployment,
        TenantContextStore $tenantContext,
        AccessResolver $accessResolver,
        LabelNormalizer $normalizer,
    ): RedirectResponse {
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

        $decision = $accessResolver->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('deployment.update'),
            $model,
            $context,
        );

        if (! $decision->allowed) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        $model->update([
            'labels' => $normalizer->normalize($request->string('labels')->toString()),
        ]);

        return redirect()
            ->route('dashboard')
            ->with('status', __('racklab.dashboard.labels_saved'));
    }
}
