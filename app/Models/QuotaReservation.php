<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $quota_limit_id
 * @property string|null $project_id
 * @property string|null $deployment_id
 * @property string|null $deployment_operation_id
 * @property int|null $actor_user_id
 * @property string $scope_type
 * @property string $scope_id
 * @property string $dimension
 * @property int $quantity
 * @property string $state
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'quota_limit_id',
    'project_id',
    'deployment_id',
    'deployment_operation_id',
    'actor_user_id',
    'scope_type',
    'scope_id',
    'dimension',
    'quantity',
    'state',
    'expires_at',
    'metadata',
])]
class QuotaReservation extends Model
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
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'expires_at' => 'datetime',
            'metadata' => 'array',
            'quantity' => 'integer',
        ];
    }
}
