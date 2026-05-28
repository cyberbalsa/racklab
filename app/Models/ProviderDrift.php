<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantScopedResource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $project_id
 * @property string $provider
 * @property string $resource_type
 * @property string $resource_id
 * @property string|null $resource_label
 * @property string $state
 * @property array<string, mixed> $expected_state
 * @property array<string, mixed> $observed_state
 * @property list<array<string, mixed>> $drift
 * @property \Illuminate\Support\Carbon $detected_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property int|null $resolved_by_id
 * @property string|null $resolution
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'provider',
    'resource_type',
    'resource_id',
    'resource_label',
    'state',
    'expected_state',
    'observed_state',
    'drift',
    'detected_at',
    'resolved_at',
    'resolved_by_id',
    'resolution',
    'metadata',
])]
class ProviderDrift extends Model implements TenantScopedResource
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
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    protected function tenantResourceTypeName(): string
    {
        return 'provider_drift';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'drift' => 'array',
            'expected_state' => 'array',
            'metadata' => 'array',
            'observed_state' => 'array',
            'resolved_at' => 'datetime',
            'resolved_by_id' => 'integer',
        ];
    }
}
