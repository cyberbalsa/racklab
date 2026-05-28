<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use App\Identity\PersonalProjectProvisioner;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('audit logs local signup and the resulting login', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);

    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada-audit@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'ada-audit@example.test')->firstOrFail();

    expect(AuditEvent::query()->where('event_type', 'auth.signup')->where('actor_id', (string) $user->id)->first()?->actor_tenant)
        ->toBe($tenant->getKey())
        ->and(AuditEvent::query()->where('event_type', 'auth.login')->where('actor_id', (string) $user->id)->where('result', 'allowed')->exists())
        ->toBeTrue();
});

it('audit logs failed local login without storing the submitted password', function (): void {
    Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    User::factory()->create([
        'email' => 'failed-login@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    $this->post('/login', [
        'email' => 'failed-login@example.test',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors();

    $event = AuditEvent::query()->where('event_type', 'auth.failed_login')->firstOrFail();

    expect($event->result)->toBe('denied')
        ->and($event->metadata)->toHaveKey('email_hash')
        ->and(json_encode($event->metadata))->not->toContain('wrong-password');
});

it('audit logs logout for the authenticated user', function (): void {
    [$tenant, $user] = provisionAuthAuditUser('logout@example.test');

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    expect(AuditEvent::query()
        ->where('event_type', 'auth.logout')
        ->where('actor_id', (string) $user->id)
        ->where('actor_tenant', $tenant->getKey())
        ->exists())->toBeTrue();
});

it('updates the user password and audit logs the password-change event', function (): void {
    [$tenant, $user] = provisionAuthAuditUser('password-change@example.test');

    $this->actingAs($user)
        ->put('/user/password', [
            'current_password' => 'correct-horse-battery-staple',
            'password' => 'new-correct-horse-battery-staple',
            'password_confirmation' => 'new-correct-horse-battery-staple',
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect(Hash::check('new-correct-horse-battery-staple', $user->password))->toBeTrue()
        ->and(AuditEvent::query()
            ->where('event_type', 'auth.password_change')
            ->where('actor_id', (string) $user->id)
            ->where('actor_tenant', $tenant->getKey())
            ->exists())->toBeTrue();
});

/**
 * @return array{Tenant, User}
 */
function provisionAuthAuditUser(string $email): array
{
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create([
        'email' => $email,
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);
    $context = new TenantContext(activeTenantId: $tenant->getKey());

    app(TenantContextStore::class)->set($context);
    $tenant->makeCurrent();
    app(PersonalProjectProvisioner::class)->ensureFor($user, $context);
    app(TenantContextStore::class)->forget();
    Tenant::forgetCurrent();

    return [$tenant, $user];
}
