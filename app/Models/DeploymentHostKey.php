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
 * @property string|null $deployment_resource_id
 * @property string $key_type
 * @property string $public_key
 * @property string $fingerprint
 * @property \Illuminate\Support\Carbon $first_seen_at
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'deployment_id',
    'deployment_resource_id',
    'key_type',
    'public_key',
    'fingerprint',
    'first_seen_at',
    'metadata',
])]
class DeploymentHostKey extends Model
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
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
