<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

it('renders an authorized noVNC console pane on the deployment detail page', function (): void {
    [, $user, $deployment] = provisionDeploymentConsoleBrowserFixture();

    $this->browse(function (Browser $browser) use ($user, $deployment): void {
        $browser
            ->loginAs($user)
            ->visit('/deployments/'.$deployment->getKey())
            ->waitForText($deployment->name)
            ->assertPresent('[data-testid="deployment-detail"]')
            ->assertPresent('[data-testid="console-pane-authorized"]')
            ->assertPresent('[data-testid="novnc-viewer"]')
            ->assertSee('Connect')
            ->assertMissing('[data-testid="console-pane-unauthorized"]');

        // The Connect button must NOT leak the JWT into the rendered HTML.
        expect($browser->driver->getPageSource())->not->toContain('eyJ');
    });
});

it('connects the console pane through a real audited grant when Connect is clicked', function (): void {
    [, $user, $deployment] = provisionDeploymentConsoleBrowserFixture();

    $this->browse(function (Browser $browser) use ($user, $deployment): void {
        $browser
            ->loginAs($user)
            ->visit('/deployments/'.$deployment->getKey())
            ->waitFor('@console-connect-'.$deployment->resourceId())
            ->click('@console-connect-'.$deployment->resourceId())
            // The glue requests a grant, then mounts the island, which moves
            // the pane to connecting → connected.
            ->waitUsing(10, 200, fn (): bool => in_array(
                $browser->attribute('[data-testid="novnc-viewer"]', 'data-connection-state'),
                ['connecting', 'connected'],
                true,
            ));

        // The grant JWT must never appear in the DOM.
        expect($browser->driver->getPageSource())->not->toContain('eyJ');
    });

    // Clicking Connect issued a real, audited console grant.
    expect(AuditEvent::query()
        ->where('event_type', 'console.session.start')
        ->where('result', 'allowed')
        ->where('resource_id', $deployment->resourceId())
        ->exists())->toBeTrue();
});

it('hides the deployment detail page entirely from a same-tenant actor without deployment.read', function (): void {
    [$tenant, , $deployment] = provisionDeploymentConsoleBrowserFixture();

    $outsider = User::factory()->create([
        'name' => 'Browser Outsider',
        'email' => 'browser-outsider@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($outsider, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    $this->browse(function (Browser $browser) use ($outsider, $deployment): void {
        $browser
            ->loginAs($outsider)
            ->visit('/deployments/'.$deployment->getKey())
            // 404 page from Laravel: confirm no deployment-detail markers leak.
            ->assertMissing('[data-testid="deployment-detail"]')
            ->assertMissing('[data-testid="console-pane-authorized"]')
            ->assertMissing('[data-testid="novnc-viewer"]')
            ->assertDontSee($deployment->name)
            ->assertDontSee($deployment->getKey());
    });
});

/**
 * @return array{Tenant, User, Deployment}
 */
function provisionDeploymentConsoleBrowserFixture(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default Tenant'],
    );
    $user = User::factory()->create([
        'name' => 'Browser Console Owner',
        'email' => 'browser-console-owner@example.test',
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    Laravel\Sanctum\Sanctum::actingAs($user);
    $deploymentId = test()->postJson('/api/v1/deployments', [
        'project_id' => $project->getKey(),
        'operation' => 'add_vm',
        'idempotency_key' => 'browser-console-fixture',
    ])->assertCreated()->json('data.id');
    auth()->forgetGuards();

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->whereKey($deploymentId)->firstOrFail();

    return [$tenant, $user, $deployment];
}
