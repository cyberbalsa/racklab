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
 * @property string $router_id
 * @property string $network_id
 * @property string|null $subnet_id
 * @property string|null $interface_ip
 * @property string $state
 * @property array<string, mixed>|null $provider_binding
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'router_id',
    'network_id',
    'subnet_id',
    'interface_ip',
    'state',
    'provider_binding',
    'metadata',
])]
class RouterNetwork extends Model
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
     * @return BelongsTo<Router, $this>
     */
    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * @return BelongsTo<Subnet, $this>
     */
    public function subnet(): BelongsTo
    {
        return $this->belongsTo(Subnet::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'provider_binding' => 'array',
            'metadata' => 'array',
        ];
    }
}
