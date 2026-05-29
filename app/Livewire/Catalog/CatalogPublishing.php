<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Catalog\CatalogPublisher;
use App\Catalog\DuplicateCatalogVersionException;
use App\Catalog\VisibleCatalogList;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Project;
use App\Models\StackDefinition;
use App\Models\User;
use App\Projects\VisibleProjectList;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Catalog publishing Key Screen (PRD §15, instructor): publish a project-local
 * stack to the tenant catalog as a new published version. Lists the stacks the
 * actor may publish (project-local stacks in projects where they hold
 * `catalog.publish`) and the tenant's already-published catalog items. The
 * actual publish is gated again in CatalogPublisher.
 */
final class CatalogPublishing extends Component
{
    public string $selectedStackId = '';

    public string $itemName = '';

    public string $versionLabel = '1.0.0';

    public function publish(CatalogPublisher $publisher): void
    {
        $this->validate([
            'selectedStackId' => ['required', 'string'],
            'itemName' => ['required', 'string', 'max:120'],
            'versionLabel' => ['required', 'string', 'max:60'],
        ], [], [
            'selectedStackId' => __('racklab.publish.field_stack'),
            'itemName' => __('racklab.publish.field_name'),
            'versionLabel' => __('racklab.publish.field_version'),
        ]);

        $user = $this->currentUser();
        $context = $this->currentContext();

        // Only stacks the actor may actually publish are eligible — guards
        // against a forged selectedStackId.
        $stack = collect($this->publishableStacks($user, $context))
            ->first(fn (StackDefinition $candidate): bool => $candidate->getKey() === $this->selectedStackId);

        if (! $stack instanceof StackDefinition) {
            $this->addError('selectedStackId', __('racklab.publish.stack_unavailable'));

            return;
        }

        try {
            $publisher->publish($user, $context, $stack, $this->itemName, $this->versionLabel, null);
        } catch (AuthorizationException) {
            $this->addError('publish', __('racklab.publish.denied'));

            return;
        } catch (DuplicateCatalogVersionException) {
            $this->addError('versionLabel', __('racklab.publish.duplicate_version'));

            return;
        }

        session()->flash('status', __('racklab.publish.published', ['name' => $this->itemName]));
        $this->reset(['selectedStackId', 'itemName']);
        $this->versionLabel = '1.0.0';
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        return view('livewire.catalog.catalog-publishing', [
            'stacks' => $this->publishableStacks($user, $context),
            // AccessResolver catalog.read-filtered, same as the catalog browser
            // — never a raw enumeration of every catalog item.
            'publishedItems' => app(VisibleCatalogList::class)->forUser($user, $context),
        ]);
    }

    /**
     * Project-local stacks in projects where the actor holds catalog.publish.
     *
     * @return list<StackDefinition>
     */
    private function publishableStacks(User $user, TenantContext $context): array
    {
        $stacks = [];

        foreach (app(VisibleProjectList::class)->forUser($user, $context) as $project) {
            if (! $this->allows($user, 'catalog.publish', $project, $context)) {
                continue;
            }

            /** @var StackDefinition $stack */
            foreach (StackDefinition::query()
                ->where('project_id', $project->id)
                ->where('scope', 'project_local')
                ->where('is_reserved_default', false)
                ->orderBy('name')
                ->get() as $stack) {
                $stacks[] = $stack;
            }
        }

        return $stacks;
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
