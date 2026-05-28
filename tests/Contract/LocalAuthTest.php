<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('registers a local user into the default tenant and creates their personal project', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);

    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'ada@example.test')->firstOrFail();

    $this->assertAuthenticatedAs($user);

    expect(TenantMembership::query()->whereBelongsTo($tenant)->whereBelongsTo($user)->exists())->toBeTrue()
        ->and(Project::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('created_for_user_id', $user->getKey())
            ->where('is_personal_default', true)
            ->exists())->toBeTrue();
});

it('registers a local user after the clean-install bootstrap seed', function (): void {
    $this->artisan('db:seed')->assertExitCode(0);

    $this->post('/register', [
        'name' => 'Katherine Johnson',
        'email' => 'katherine@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'katherine@example.test')->firstOrFail();
    $tenant = Tenant::query()->where('slug', 'default')->firstOrFail();

    expect(TenantMembership::query()->whereBelongsTo($tenant)->whereBelongsTo($user)->exists())->toBeTrue()
        ->and(Project::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('created_for_user_id', $user->getKey())
            ->where('is_personal_default', true)
            ->exists())->toBeTrue();
});

it('provisions a personal project for an existing local user on login', function (): void {
    Tenant::query()->create(['name' => 'Default Tenant', 'slug' => 'default']);
    $user = User::factory()->create([
        'email' => 'grace@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    $this->post('/login', [
        'email' => 'grace@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);

    expect(Project::query()
        ->where('created_for_user_id', $user->getKey())
        ->where('is_personal_default', true)
        ->exists())->toBeTrue();
});
