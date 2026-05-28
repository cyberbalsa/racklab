<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $deployment_id
 * @property string $component_key
 * @property string $kind
 * @property string $state
 * @property string $provider
 * @property string|null $provider_resource_id
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'deployment_id',
    'component_key',
    'kind',
    'state',
    'provider',
    'provider_resource_id',
    'metadata',
])]
class DeploymentResource extends Model
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
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return HasMany<DeploymentNetworkBinding, $this>
     */
    public function networkBindings(): HasMany
    {
        return $this->hasMany(DeploymentNetworkBinding::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
