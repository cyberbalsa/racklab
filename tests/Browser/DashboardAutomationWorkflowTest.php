<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\ScriptRun;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\RbacDefaultsSynchronizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;

uses(DatabaseMigrations::class);

it('runs dashboard Ansible automation and downloads its result artifact in a real browser', function (): void {
    [$tenant, $user, $project] = provisionDashboardAutomationBrowserProject();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser
            ->loginAs($user)
            ->visit('/dashboard')
            ->waitForText('Automation')
            ->assertSee('Run Ansible')
            ->click('@run-ansible')
            ->waitForText('ansible_result')
            ->assertSee('ansible')
            ->assertSee('succeeded');
    });

    /** @var ScriptRun $run */
    $run = ScriptRun::query()
        ->where('tenant_id', $tenant->getKey())
        ->where('project_id', $project->getKey())
        ->where('runner_kind', 'ansible')
        ->firstOrFail();

    /** @var Artifact $artifact */
    $artifact = Artifact::query()->whereKey($run->metadata['output_artifact_ids'][0])->firstOrFail();

    $this->browse(function (Browser $browser) use ($artifact): void {
        $browser
            ->visit('/dashboard')
            ->waitForText($artifact->getKey())
            ->click('@script-artifact-'.$artifact->getKey())
            ->waitForText('"runner":"ansible"')
            ->assertSee('"status":"ok"');
    });
});

/**
 * @return array{Tenant, User, Project}
 */
function provisionDashboardAutomationBrowserProject(): array
{
    app(RbacDefaultsSynchronizer::class)->sync();

    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create([
        'name' => 'Dashboard Automation Admin',
        'email' => 'dashboard-automation@example.test',
    ]);

    $context = new TenantContext(activeTenantId: $tenant->getKey());
    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();

    $project = app(PersonalProjectProvisioner::class)->ensureFor($user, $context);

    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user, $project];
}
