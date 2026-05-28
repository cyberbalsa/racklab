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
 * @property string $event_type
 * @property string $result
 * @property string|null $scope_type
 * @property string|null $scope_id
 * @property string|null $dimension
 * @property int|null $quantity
 * @property int|null $limit_value
 * @property int|null $actor_user_id
 * @property string|null $project_id
 * @property string|null $deployment_id
 * @property string|null $deployment_operation_id
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable([
    'tenant_id',
    'event_type',
    'result',
    'scope_type',
    'scope_id',
    'dimension',
    'quantity',
    'limit_value',
    'actor_user_id',
    'project_id',
    'deployment_id',
    'deployment_operation_id',
    'metadata',
    'created_at',
])]
class QuotaEvent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

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
            'created_at' => 'datetime',
            'limit_value' => 'integer',
            'metadata' => 'array',
            'quantity' => 'integer',
        ];
    }
}
