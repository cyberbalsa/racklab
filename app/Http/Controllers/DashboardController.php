<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Courses\VisibleCourseList;
use App\Deployments\VisibleDeploymentList;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Tenant;
use App\Models\TokenGrant;
use App\Models\User;
use App\Projects\VisibleProjectList;
use App\Quota\DashboardQuotaSummary;
use App\Scripts\VisibleScriptRunList;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        TenantContextStore $tenantContext,
        VisibleProjectList $projects,
        VisibleCourseList $courses,
        VisibleDeploymentList $deployments,
        VisibleScriptRunList $scriptRuns,
        DashboardQuotaSummary $quotaSummary,
    ): Factory|View {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $context = $tenantContext->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($context->activeTenantId);

        if (! $tenant instanceof Tenant) {
            throw new NotFoundHttpException('Tenant not found.');
        }

        $visibleProjects = $projects->forUser($user, $context);
        $labelFilter = trim($request->string('label')->toString());
        $labelFilter = $labelFilter === '' ? null : $labelFilter;

        return view('dashboard', [
            'activeTenant' => $tenant,
            'courses' => $courses->forUser($user, $context),
            'projects' => $visibleProjects,
            'quotaSummaries' => $quotaSummary->forProjects($user, $context, $visibleProjects),
            'deployments' => $deployments->forUser($user, $context, $labelFilter),
            'labelFilter' => $labelFilter,
            'scriptRuns' => $scriptRuns->forUser($user, $context),
            'tokenGrants' => TokenGrant::query()
                ->where('owner_user_id', $user->id)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}
