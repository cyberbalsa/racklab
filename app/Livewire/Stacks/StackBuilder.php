<?php

declare(strict_types=1);

namespace App\Livewire\Stacks;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\User;
use App\Networking\VisibleNetworkOfferingList;
use App\Projects\VisibleProjectList;
use App\Stacks\ProjectStackAuthoring;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Browser stack builder: compose a project-local stack by naming it, adding VM
 * components, and attaching tenant network offerings to each VM, then save it
 * as a deployable project-local StackDefinition. Reuses VisibleNetworkOffering
 * List for the network picker and ProjectStackAuthoring (project.update gated)
 * for persistence.
 */
final class StackBuilder extends Component
{
    public string $selectedProjectId = '';

    public string $stackName = '';

    /**
     * @var array<int, array{key: string, networks: array<int, array{key: string, offering_slug: string}>}>
     */
    public array $vms = [];

    public function addVm(): void
    {
        $this->vms[] = [
            'key' => 'vm'.($this->vms === [] ? '' : '-'.(count($this->vms) + 1)),
            'networks' => [],
        ];
    }

    public function removeVm(int $index): void
    {
        if (isset($this->vms[$index])) {
            unset($this->vms[$index]);
            $this->vms = array_values($this->vms);
        }
    }

    public function attachNetwork(int $vmIndex, string $offeringSlug): void
    {
        if (! isset($this->vms[$vmIndex])) {
            return;
        }

        $available = array_map(
            static fn (\App\Models\NetworkOffering $offering): string => (string) $offering->slug,
            app(VisibleNetworkOfferingList::class)->forUser($this->currentUser(), $this->currentContext()),
        );

        if (! in_array($offeringSlug, $available, strict: true)) {
            $this->addError('vms', __('racklab.stacks.unknown_offering'));

            return;
        }

        $nicIndex = count($this->vms[$vmIndex]['networks']);
        $this->vms[$vmIndex]['networks'][] = [
            'key' => 'eth'.$nicIndex,
            'offering_slug' => $offeringSlug,
        ];
    }

    public function detachNetwork(int $vmIndex, int $nicIndex): void
    {
        if (isset($this->vms[$vmIndex]['networks'][$nicIndex])) {
            unset($this->vms[$vmIndex]['networks'][$nicIndex]);
            $this->vms[$vmIndex]['networks'] = array_values($this->vms[$vmIndex]['networks']);
        }
    }

    public function save(ProjectStackAuthoring $authoring): mixed
    {
        $this->validate([
            'stackName' => ['required', 'string', 'max:120'],
            'selectedProjectId' => ['required', 'string'],
        ], [], ['stackName' => __('racklab.stacks.field_name'), 'selectedProjectId' => __('racklab.stacks.field_project')]);

        $user = $this->currentUser();
        $context = $this->currentContext();

        /** @var Project|null $project */
        $project = Project::query()->whereKey($this->selectedProjectId)->first();

        if (! $project instanceof Project) {
            $this->addError('selectedProjectId', __('racklab.stacks.select_project_error'));

            return null;
        }

        try {
            $authoring->create($user, $context, $project, $this->stackName, $this->definition());
        } catch (AuthorizationException) {
            $this->addError('save', __('racklab.stacks.save_denied'));

            return null;
        }

        session()->flash('status', __('racklab.stacks.saved'));

        return $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        $projects = app(VisibleProjectList::class)->forUser($user, $context);

        if ($this->selectedProjectId === '') {
            foreach ($projects as $project) {
                if ($project->is_personal_default) {
                    $this->selectedProjectId = $project->id;

                    break;
                }
            }

            if ($this->selectedProjectId === '' && $projects !== []) {
                $this->selectedProjectId = $projects[0]->id;
            }
        }

        $projectIds = array_map(static fn (Project $project): string => $project->id, $projects);

        return view('livewire.stacks.stack-builder', [
            'projects' => $projects,
            'offerings' => app(VisibleNetworkOfferingList::class)->forUser($user, $context),
            'savedStacks' => $projectIds === [] ? [] : StackDefinition::query()
                ->whereIn('project_id', $projectIds)
                ->where('scope', 'project_local')
                ->where('is_reserved_default', false)
                ->orderBy('name')
                ->get()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'version' => 1,
            'provider' => 'fake',
            'components' => array_map(
                static fn (array $vm): array => [
                    'key' => $vm['key'],
                    'kind' => 'vm',
                    'networks' => array_values($vm['networks']),
                ],
                $this->vms,
            ),
        ];
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
