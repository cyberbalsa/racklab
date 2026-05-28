<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $deployment_id
 * @property string $deployment_resource_id
 * @property string $network_offering_id
 * @property string $provider_network_id
 * @property string $component_key
 * @property string $nic_key
 * @property string $reachability
 * @property string $state
 * @property string $provider
 * @property array<string, mixed>|null $provider_binding
 * @property string|null $management_host
 * @property int|null $management_port
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'deployment_id',
    'deployment_resource_id',
    'network_offering_id',
    'provider_network_id',
    'component_key',
    'nic_key',
    'reachability',
    'state',
    'provider',
    'provider_binding',
    'management_host',
    'management_port',
    'metadata',
])]
class DeploymentNetworkBinding extends Model
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
     * @return BelongsTo<DeploymentResource, $this>
     */
    public function deploymentResource(): BelongsTo
    {
        return $this->belongsTo(DeploymentResource::class);
    }

    /**
     * @return BelongsTo<NetworkOffering, $this>
     */
    public function networkOffering(): BelongsTo
    {
        return $this->belongsTo(NetworkOffering::class);
    }

    /**
     * @return BelongsTo<ProviderNetwork, $this>
     */
    public function providerNetwork(): BelongsTo
    {
        return $this->belongsTo(ProviderNetwork::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'management_port' => 'integer',
            'metadata' => 'array',
            'provider_binding' => 'array',
        ];
    }
}
