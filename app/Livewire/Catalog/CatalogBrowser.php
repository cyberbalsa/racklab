<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Catalog\CatalogDeployer;
use App\Catalog\VisibleCatalogList;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Project;
use App\Models\User;
use App\Projects\VisibleProjectList;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Browser catalog page: lists the published catalog items the actor may read
 * in the active tenant (AccessResolver `catalog.read`) as cards, and deploys a
 * chosen version into a selected project through the shared CatalogDeployer
 * (which enforces catalog.read + deployment.create).
 */
final class CatalogBrowser extends Component
{
    public string $selectedProjectId = '';

    public function deploy(string $catalogVersionId, CatalogDeployer $deployer): mixed
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        /** @var Project|null $project */
        $project = Project::query()->whereKey($this->selectedProjectId)->first();

        if (! $project instanceof Project) {
            $this->addError('selectedProjectId', __('racklab.catalog.select_project_error'));

            return null;
        }

        try {
            $deployer->deploy(
                user: $user,
                context: $context,
                project: $project,
                request: request(),
                operationKind: 'deploy',
                idempotencyKey: 'catalog-deploy-'.Str::ulid()->toString(),
                catalogVersionId: $catalogVersionId,
            );
        } catch (NotFoundHttpException|AuthorizationException) {
            $this->addError('deploy', __('racklab.catalog.deploy_denied'));

            return null;
        }

        session()->flash('status', __('racklab.catalog.deploy_started'));

        return $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        $projects = app(VisibleProjectList::class)->forUser($user, $context);

        if ($this->selectedProjectId === '') {
            $this->selectedProjectId = $this->defaultProjectId($projects);
        }

        return view('livewire.catalog.catalog-browser', [
            'catalogItems' => app(VisibleCatalogList::class)->forUser($user, $context),
            'projects' => $projects,
        ]);
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function currentContext(): TenantContext
    {
        $context = app(TenantContextStore::class)->current();

        if (! $context instanceof TenantContext) {
            throw new NotFoundHttpException('Tenant context not found.');
        }

        return $context;
    }

    /**
     * @param  list<Project>  $projects
     */
    private function defaultProjectId(array $projects): string
    {
        foreach ($projects as $project) {
            if ($project->is_personal_default) {
                return $project->id;
            }
        }

        return $projects === [] ? '' : $projects[0]->id;
    }
}
