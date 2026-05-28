<?php

declare(strict_types=1);

namespace App\Livewire\Console;

use App\Auth\Jwt\ConsoleAccessGrantIssuer;
use App\Domain\Console\ConsoleKind;
use App\Domain\Rbac\Permission;
use App\Domain\Tenancy\AccessResolver;
use App\Domain\Tenancy\ActorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class DeploymentConsolePane extends Component
{
    public string $deploymentId = '';

    public string $consoleKindValue = 'vnc';

    public string $deploymentName = '';

    public bool $canConnect = false;

    public string $statusKey = 'racklab.console.idle';

    public function mount(Deployment $deployment, ConsoleKind $consoleKind = ConsoleKind::Vnc): void
    {
        $this->deploymentId = $deployment->resourceId();
        $this->deploymentName = $deployment->name;
        $this->consoleKindValue = $consoleKind->value;

        $this->canConnect = $this->resolveCanConnect($deployment);
    }

    public function render(): View
    {
        return view('livewire.console.deployment-console-pane');
    }

    public function consoleKind(): ConsoleKind
    {
        return ConsoleKind::fromName($this->consoleKindValue);
    }

    private function resolveCanConnect(Deployment $deployment): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $context = app(TenantContextStore::class)->current();

        if (! $context instanceof TenantContext) {
            return false;
        }

        $decision = app(AccessResolver::class)->permitted(
            new ActorIdentity((string) $user->id),
            new Permission(ConsoleAccessGrantIssuer::CONNECT_PERMISSION),
            $deployment,
            $context,
        );

        return $decision->allowed;
    }
}
