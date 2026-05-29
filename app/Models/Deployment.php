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
 * @property string|null $course_id
 * @property string $stack_definition_id
 * @property int|null $requested_by_id
 * @property string $name
 * @property string $state
 * @property string $provider
 * @property \Illuminate\Support\Carbon|null $lease_expires_at
 * @property array<string, mixed>|null $metadata
 * @property list<string>|null $labels
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'course_id',
    'stack_definition_id',
    'requested_by_id',
    'name',
    'state',
    'provider',
    'lease_expires_at',
    'metadata',
    'labels',
    'sharing_scope',
    'shared_with_tenants',
])]
class Deployment extends Model implements TenantScopedResource
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
     * @return BelongsTo<StackDefinition, $this>
     */
    public function stackDefinition(): BelongsTo
    {
        return $this->belongsTo(StackDefinition::class);
    }

    /**
     * @return HasMany<DeploymentResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(DeploymentResource::class);
    }

    /**
     * @return HasMany<DeploymentOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(DeploymentOperation::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'deployment';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'datetime',
            'metadata' => 'array',
            'labels' => 'array',
            'shared_with_tenants' => 'array',
        ];
    }
}
