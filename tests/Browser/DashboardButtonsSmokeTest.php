<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

it('drives every primary dashboard button: New VM, label, filter, release, Run Console', function (): void {
    [$tenant, $user, $project] = provisionDashboardSmokeActor();

    // New VM (default-stack add_vm on the fake provider, runs synchronously).
    $this->browse(function (Browser $browser) use ($project): void {
        $browser
            ->loginAs(authUser())
            ->visit('/dashboard')
            ->waitForText('Deployments')
            ->click('@new-vm-'.$project->getKey())
            ->waitForLocation('/dashboard')
            ->waitForText('running');
    });

    /** @var Deployment $deployment */
    $deployment = Deployment::query()->where('project_id', $project->getKey())->firstOrFail();

    // Label the deployment, then filter the dashboard by the label badge.
    $this->browse(function (Browser $browser) use ($deployment): void {
        $browser
            ->visit('/dashboard')
            ->waitFor('@deployment-labels-'.$deployment->getKey())
            ->type('@deployment-labels-'.$deployment->getKey(), 'smoke-test')
            ->click('@save-labels-'.$deployment->getKey())
            ->waitForText('Labels updated.')
            ->assertSee('smoke-test')
            ->clickLink('smoke-test')
            ->waitForText('Clear filter')
            ->assertSee($deployment->getKey());
    });

    expect($deployment->fresh()?->labels)->toBe(['smoke-test']);

    // Run Console automation (fake runner, synchronous).
    $this->browse(function (Browser $browser): void {
        $browser
            ->visit('/dashboard')
            ->waitForText('Automation')
            ->click('@run-console')
            ->waitForText('console_script');
    });

    // Release the deployment.
    $this->browse(function (Browser $browser) use ($deployment): void {
        $browser
            ->visit('/dashboard')
            ->waitFor('@release-'.$deployment->getKey())
            ->click('@release-'.$deployment->getKey())
            ->waitForLocation('/dashboard');
    });

    expect($deployment->fresh()?->state)->toBe('released');
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionDashboardSmokeActor(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create(['name' => 'Smoke User', 'email' => 'smoke@example.test']);
    $GLOBALS['__dashboard_smoke_user'] = $user;

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}

function authUser(): User
{
    /** @var User $user */
    $user = $GLOBALS['__dashboard_smoke_user'];

    return $user;
}
