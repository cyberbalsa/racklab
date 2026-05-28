<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Deployments\VisibleDeploymentList;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Project;
use App\Models\ProjectSshKey;
use App\Models\StackDefinition;
use App\Models\User;
use App\Quota\DashboardQuotaSummary;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Project detail Key Screen (PRD §15): a single project's quota, its
 * deployments, its project-local stacks, and its SSH keys. Gated by
 * `project.read` through AccessResolver (404 on denial, no existence leak);
 * SSH keys are shown only when the actor also holds `project.ssh_key.read`.
 */
final class ProjectDetail extends Component
{
    public string $projectId = '';

    public function mount(string $project): void
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        $model = Project::query()->whereKey($project)->first();

        if (! $model instanceof Project || ! $this->allows($user, 'project.read', $model, $context)) {
            throw new NotFoundHttpException('Project not found.');
        }

        $this->projectId = $model->id;
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        $project = Project::query()->whereKey($this->projectId)->first();

        // Re-check on every render: a binding could have been revoked between
        // the initial mount and a later Livewire roundtrip.
        if (! $project instanceof Project || ! $this->allows($user, 'project.read', $project, $context)) {
            throw new NotFoundHttpException('Project not found.');
        }

        $canReadSshKeys = $this->allows($user, 'project.ssh_key.read', $project, $context);

        return view('livewire.projects.project-detail', [
            'project' => $project,
            'quota' => app(DashboardQuotaSummary::class)->forProjects($user, $context, [$project])[$project->id] ?? [],
            'deployments' => app(VisibleDeploymentList::class)->forUser($user, $context, projectId: $project->id),
            'stacks' => StackDefinition::query()
                ->where('project_id', $project->id)
                ->where('scope', 'project_local')
                ->where('is_reserved_default', false)
                ->orderBy('name')
                ->get()
                ->all(),
            'canReadSshKeys' => $canReadSshKeys,
            'sshKeys' => $canReadSshKeys
                ? ProjectSshKey::query()->where('project_id', $project->id)->orderBy('name')->get()->all()
                : [],
        ]);
    }

    private function allows(User $user, string $permission, Project $project, TenantContext $context): bool
    {
        return app(AccessResolver::class)->permitted(
            new ActorIdentity((string) $user->id),
            new Permission($permission),
            $project,
            $context,
        )->allowed;
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
}
