<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Project;
use App\Models\Script;
use App\Models\ScriptApproval;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Script library Key Screen (PRD §15): the scripts defined in a project, with
 * runner kind, current version, and approval state. Authorized at the project
 * level — `project.read` gates the page (404 on denial); `script.read` on the
 * project gates whether scripts are listed (scripts are project-scoped and
 * authorized through their project, never a per-script binding).
 */
final class ScriptLibrary extends Component
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

        if (! $project instanceof Project || ! $this->allows($user, 'project.read', $project, $context)) {
            throw new NotFoundHttpException('Project not found.');
        }

        $canViewScripts = $this->allows($user, 'script.read', $project, $context);

        return view('livewire.scripts.script-library', [
            'project' => $project,
            'canViewScripts' => $canViewScripts,
            'scripts' => $canViewScripts ? $this->scriptsFor($project) : [],
        ]);
    }

    /**
     * @return list<array{model: Script, approved: bool}>
     */
    private function scriptsFor(Project $project): array
    {
        $rows = [];

        /** @var Script $script */
        foreach (Script::query()->where('project_id', $project->id)->orderBy('name')->get() as $script) {
            $approved = $script->current_version_id !== null
                && ScriptApproval::query()
                    ->where('script_id', $script->getKey())
                    ->where('script_version_id', $script->current_version_id)
                    ->where('state', 'active')
                    ->whereNull('invalidated_at')
                    ->exists();

            $rows[] = ['model' => $script, 'approved' => $approved];
        }

        return $rows;
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
