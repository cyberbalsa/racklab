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
 * @property string $security_group_id
 * @property int $position
 * @property string $direction
 * @property string $protocol
 * @property string $ethertype
 * @property int|null $port_min
 * @property int|null $port_max
 * @property string|null $remote_cidr
 * @property string $state
 * @property string|null $provider_rule_id
 * @property array<string, mixed>|null $provider_binding
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'security_group_id',
    'position',
    'direction',
    'protocol',
    'ethertype',
    'port_min',
    'port_max',
    'remote_cidr',
    'state',
    'provider_rule_id',
    'provider_binding',
    'metadata',
])]
class SecurityGroupRule extends Model
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
     * @return BelongsTo<SecurityGroup, $this>
     */
    public function securityGroup(): BelongsTo
    {
        return $this->belongsTo(SecurityGroup::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'port_max' => 'integer',
            'port_min' => 'integer',
            'position' => 'integer',
            'provider_binding' => 'array',
        ];
    }
}
