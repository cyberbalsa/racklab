<?php

declare(strict_types=1);

namespace App\Livewire\Sharing;

use App\Deployments\ConsoleShareService;
use App\Deployments\VisibleDeploymentList;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Deployment;
use App\Models\RoleBinding;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sharing view Key Screen (PRD §15): manage who can use the consoles of the
 * deployments you own. The use case is one group of students granting another
 * group console access to a lab VM. Lists the deployments the actor can manage
 * (deployment.update); each can be shared with tenant members by email
 * (console_guest binding) and individual grantees revoked.
 */
final class ConsoleSharing extends Component
{
    /**
     * Per-deployment email textarea inputs, keyed by deployment id.
     *
     * @var array<string, string>
     */
    public array $emailInputs = [];

    public function share(string $deploymentId, ConsoleShareService $service): void
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        $deployment = Deployment::query()->whereKey($deploymentId)->first();

        if (! $deployment instanceof Deployment || ! $this->canManage($user, $deployment, $context)) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        $result = $service->share($user, $context, $deployment, $this->emailInputs[$deploymentId] ?? '');

        $this->emailInputs[$deploymentId] = '';
        session()->flash('status', __('racklab.sharing.shared_summary', [
            'shared' => $result->shared,
            'already' => $result->alreadyShared,
            'missing' => $result->missing === [] ? '—' : implode(', ', $result->missing),
        ]));
    }

    public function revoke(string $deploymentId, int $userId, ConsoleShareService $service): void
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        $deployment = Deployment::query()->whereKey($deploymentId)->first();

        if (! $deployment instanceof Deployment || ! $this->canManage($user, $deployment, $context)) {
            throw new NotFoundHttpException('Deployment not found.');
        }

        $service->revoke($user, $context, $deployment, $userId);
        session()->flash('status', __('racklab.sharing.revoked'));
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $context = $this->currentContext();

        $shareable = [];

        foreach (app(VisibleDeploymentList::class)->forUser($user, $context) as $deployment) {
            if (! $this->canManage($user, $deployment, $context)) {
                continue;
            }

            $shareable[] = [
                'deployment' => $deployment,
                'guests' => $this->guests($deployment),
            ];
        }

        return view('livewire.sharing.console-sharing', [
            'shareable' => $shareable,
        ]);
    }

    /**
     * @return list<array{user_id: int, name: string, email: string}>
     */
    private function guests(Deployment $deployment): array
    {
        $userIds = RoleBinding::query()
            ->where('resource_type', $deployment->resourceType())
            ->where('resource_id', $deployment->resourceId())
            ->where('role', 'console_guest')
            ->where('principal_type', 'user')
            ->pluck('principal_id')
            ->all();

        if ($userIds === []) {
            return [];
        }

        $rows = [];

        /** @var User $member */
        foreach (User::query()->whereIn('id', $userIds)->orderBy('name')->get() as $member) {
            $rows[] = ['user_id' => $member->id, 'name' => $member->name, 'email' => $member->email];
        }

        return $rows;
    }

    private function canManage(User $user, Deployment $deployment, TenantContext $context): bool
    {
        return app(AccessResolver::class)->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('deployment.update'),
            $deployment,
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
