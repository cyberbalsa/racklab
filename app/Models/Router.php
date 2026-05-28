<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantScopedResource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $name
 * @property string $slug
 * @property string $state
 * @property string $provider
 * @property string|null $provider_router_id
 * @property array<string, mixed>|null $metadata
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'name',
    'slug',
    'state',
    'provider',
    'provider_router_id',
    'metadata',
    'sharing_scope',
    'shared_with_tenants',
])]
class Router extends Model implements TenantScopedResource
{
    use BelongsToTenant;
    use HasUlids;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<RouterNetwork, $this>
     */
    public function interfaces(): HasMany
    {
        return $this->hasMany(RouterNetwork::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'router';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'shared_with_tenants' => 'array',
        ];
    }
}
