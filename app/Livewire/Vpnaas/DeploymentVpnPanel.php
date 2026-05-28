<?php

declare(strict_types=1);

namespace App\Livewire\Vpnaas;

use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Deployment;
use App\Models\NetworkVpnEndpoint;
use App\Models\User;
use App\Models\VpnClientProfile;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only VPN summary panel for the deployment-detail page.
 *
 * Lists the deployment's VPN endpoints with their binding state and the
 * authenticated user's own active profile per endpoint. Issue / download /
 * revoke happen through the API; this panel is a status view and an entry
 * point. Owner-only display is the bedrock guarantee: another user's profile
 * never appears here even for admins, mirroring the API contract.
 */
final class DeploymentVpnPanel extends Component
{
    /**
     * Codex M5c S5 P1: lock against browser-side hydration. Without this a user
     * who can open any deployment-detail page could re-submit a different
     * deploymentId via a Livewire roundtrip and harvest another deployment's
     * VPN endpoint state + binding public_ip:udp_port. The Locked attribute
     * makes Livewire refuse browser-side mutation; render() also re-authorizes
     * deployment.read on every roundtrip as belt-and-suspenders defense.
     */
    #[Locked]
    public string $deploymentId = '';

    public function mount(Deployment $deployment): void
    {
        $this->deploymentId = $deployment->resourceId();
    }

    public function render(): View
    {
        $user = auth()->user();
        $userId = $user instanceof User ? $user->id : null;

        if (! $this->actorMayReadDeployment($user)) {
            return view('livewire.vpnaas.deployment-vpn-panel', ['rows' => []]);
        }

        /** @var list<NetworkVpnEndpoint> $endpoints */
        $endpoints = NetworkVpnEndpoint::query()
            ->with('bindings')
            ->where('deployment_id', $this->deploymentId)
            ->orderBy('created_at')
            ->get()
            ->all();

        $rows = [];

        foreach ($endpoints as $endpoint) {
            /** @var VpnClientProfile|null $profile */
            $profile = $userId !== null
                ? VpnClientProfile::query()
                    ->where('network_vpn_endpoint_id', $endpoint->getKey())
                    ->where('user_id', $userId)
                    ->first()
                : null;

            $rows[] = [
                'endpoint' => $endpoint,
                'bindings' => $endpoint->bindings,
                'profile' => $profile,
                'profile_active' => $profile?->isActive() ?? false,
            ];
        }

        return view('livewire.vpnaas.deployment-vpn-panel', [
            'rows' => $rows,
        ]);
    }

    private function actorMayReadDeployment(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $context = app(TenantContextStore::class)->current();
        if (! $context instanceof TenantContext) {
            return false;
        }

        $deployment = Deployment::query()->whereKey($this->deploymentId)->first();
        if (! $deployment instanceof Deployment) {
            // Don't leak whether the deployment exists at all.
            throw new NotFoundHttpException('Deployment not found.');
        }

        $decision = app(AccessResolver::class)->permitted(
            new ActorIdentity((string) $user->id),
            new Permission('deployment.read'),
            $deployment,
            $context,
        );

        return $decision->allowed;
    }
}
