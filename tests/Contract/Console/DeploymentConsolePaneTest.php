<?php

declare(strict_types=1);

use App\Domain\Console\ConsoleKind;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Livewire\Console\DeploymentConsolePane;
use App\Models\Deployment;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the noVNC pane for an authorized actor on a VNC deployment', function (): void {
    [$tenant, $user, $deployment] = provisionConsolePaneFixture();
    actingAsConsolePaneUser($user, $tenant);

    $component = Livewire::test(DeploymentConsolePane::class, [
        'deployment' => $deployment,
        'consoleKind' => ConsoleKind::Vnc,
    ]);

    $component->assertOk()
        ->assertSee('data-testid="console-pane-authorized"', escape: false)
        ->assertSee('data-testid="novnc-viewer"', escape: false)
        ->assertSee('data-console-kind="vnc"', escape: false)
        ->assertDontSee('data-testid="xterm-console"', escape: false)
        ->assertSee('Connect')
        ->assertSet('canConnect', true);
});

it('renders the xterm pane for a terminal-kind deployment', function (): void {
    [$tenant, $user, $deployment] = provisionConsolePaneFixture();
    actingAsConsolePaneUser($user, $tenant);

    $component = Livewire::test(DeploymentConsolePane::class, [
        'deployment' => $deployment,
        'consoleKind' => ConsoleKind::Terminal,
    ]);

    $component->assertOk()
        ->assertSee('data-testid="xterm-console"', escape: false)
        ->assertSee('data-console-kind="terminal"', escape: false)
        ->assertDontSee('data-testid="novnc-viewer"', escape: false)
        ->assertSet('canConnect', true);
});

it('hides the console controls for an unauthorized actor', function (): void {
    [$tenant, , $deployment] = provisionConsolePaneFixture();

    // Provision an unrelated user in the same tenant with no role binding to the deployment.
    $outsider = User::factory()->create([
        'name' => 'Pane Outsider',
        'email' => 'pane-outsider@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($outsider, $context);

    Sanctum::actingAs($outsider);

    $component = Livewire::test(DeploymentConsolePane::class, [
        'deployment' => $deployment,
        'consoleKind' => ConsoleKind::Vnc,
    ]);

    $component->assertOk()
        ->assertSee('data-testid="console-pane-unauthorized"', escape: false)
        ->assertDontSee('data-testid="console-pane-authorized"', escape: false)
        ->assertDontSee('data-testid="novnc-viewer"', escape: false)
        ->assertSee('You do not have access to this deployment console.')
        ->assertSet('canConnect', false);
});

/**
 * @return array{Tenant, User, Deployment}
 */
function provisionConsolePaneFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Pane Owner',
        'email' => 'pane-owner@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Sanctum::actingAs($user);
    $deploymentId = test()->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'pane-fixture',
    ])->assertCreated()->json('data.id');
    auth()->forgetGuards();

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    return [$tenant, $user, $deployment];
}

function actingAsConsolePaneUser(User $user, Tenant $tenant): void
{
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    Sanctum::actingAs($user);
}
