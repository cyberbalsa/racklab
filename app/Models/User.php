<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Attributes\Untenanted;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Override;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
#[Untenanted(reason: 'identity spans tenant memberships')]
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    #[Override]
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->getTenants($panel)->isNotEmpty();
    }

    #[Override]
    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Tenant) {
            return false;
        }

        return TenantMembership::query()
            ->where('user_id', $this->id)
            ->where('tenant_id', $tenant->getKey())
            ->exists();
    }

    /**
     * @return Collection<int, Tenant>
     */
    #[Override]
    public function getTenants(Panel $panel): Collection
    {
        $memberships = TenantMembership::query()
            ->where('user_id', $this->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        $tenantIds = [];

        /** @var TenantMembership $membership */
        foreach ($memberships as $membership) {
            $tenantIds[] = $membership->tenant_id;
        }

        $tenantsById = Tenant::query()
            ->whereIn('id', $tenantIds)
            ->get()
            ->keyBy(static fn (Tenant $tenant): string => $tenant->id);

        return collect($tenantIds)
            ->map(static fn (string $tenantId): ?Tenant => $tenantsById->get($tenantId))
            ->filter(static fn (?Tenant $tenant): bool => $tenant instanceof Tenant)
            ->values();
    }

    #[Override]
    public function getDefaultTenant(Panel $panel): ?Model
    {
        /** @var TenantMembership|null $membership */
        $membership = TenantMembership::query()
            ->where('user_id', $this->id)
            ->where('is_primary', true)
            ->first();

        return $membership?->tenant;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
