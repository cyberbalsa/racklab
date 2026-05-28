<?php

declare(strict_types=1);

use App\Domain\Rbac\DefaultRoleCatalog;
use App\Domain\Tenancy\RoleBindingScopeType;
use App\Models\Project;
use App\Models\RoleBinding;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TokenGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('bootstraps the first admin user, tenant membership, personal project, and project admin binding', function (): void {
    $tenant = Tenant::query()->create(['name' => 'RIT', 'slug' => 'rit']);

    $exitCode = Artisan::call('racklab:bootstrap-admin', [
        '--email' => 'ops@example.edu',
        '--name' => 'Ops Admin',
        '--password' => 'correct horse battery staple',
        '--tenant-slug' => 'rit',
    ]);

    $user = User::query()->where('email', 'ops@example.edu')->first();
    expect($exitCode)->toBe(0)
        ->and($user)->toBeInstanceOf(User::class)
        ->and(Hash::check('correct horse battery staple', (string) $user?->password))->toBeTrue()
        ->and(TenantMembership::query()->whereBelongsTo($tenant)->whereBelongsTo($user)->exists())->toBeTrue()
        ->and(Project::query()->where('tenant_id', $tenant->id)->where('created_for_user_id', $user?->id)->where('is_personal_default', true)->exists())->toBeTrue();

    $project = Project::query()->where('created_for_user_id', $user?->id)->firstOrFail();
    $binding = RoleBinding::query()
        ->where('principal_type', 'user')
        ->where('principal_id', (string) $user?->id)
        ->where('resource_type', 'project')
        ->where('resource_id', $project->resourceId())
        ->first();

    expect($binding)->toBeInstanceOf(RoleBinding::class)
        ->and($binding?->role)->toBe('admin')
        ->and($binding?->scope_type)->toBe(RoleBindingScopeType::TenantLocal);
});

it('is idempotent and can write an initial admin token file', function (): void {
    Tenant::query()->create(['name' => 'RIT', 'slug' => 'rit']);
    $tokenFile = tempnam(sys_get_temp_dir(), 'racklab-admin-token-');
    expect($tokenFile)->toBeString();
    unlink($tokenFile);

    $first = Artisan::call('racklab:bootstrap-admin', [
        '--email' => 'ops@example.edu',
        '--password' => 'correct horse battery staple',
        '--tenant-slug' => 'rit',
        '--token-file' => $tokenFile,
    ]);
    $second = Artisan::call('racklab:bootstrap-admin', [
        '--email' => 'ops@example.edu',
        '--password' => 'correct horse battery staple',
        '--tenant-slug' => 'rit',
        '--token-file' => $tokenFile,
    ]);

    expect($first)->toBe(0)
        ->and($second)->toBe(0)
        ->and(User::query()->where('email', 'ops@example.edu')->count())->toBe(1)
        ->and(TokenGrant::query()->where('name', 'Bootstrap admin')->count())->toBe(2)
        ->and(PersonalAccessToken::query()->count())->toBe(2)
        ->and($tokenFile)->toBeFile();

    $token = trim((string) file_get_contents($tokenFile));
    $plainId = str_contains($token, '|') ? strstr($token, '|', true) : '';
    expect($token)->toContain('|')
        ->and($plainId)->not->toBeFalse()
        ->and(PersonalAccessToken::query()->whereKey((int) $plainId)->exists())->toBeTrue()
        ->and(TokenGrant::query()->latest('id')->first()?->abilities)->toBe(DefaultRoleCatalog::permissionsByRole()['admin']);
});
