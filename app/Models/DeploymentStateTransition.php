<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $deployment_id
 * @property string|null $deployment_operation_id
 * @property string|null $from_state
 * @property string $to_state
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'deployment_id',
    'deployment_operation_id',
    'from_state',
    'to_state',
    'reason',
    'metadata',
])]
class DeploymentStateTransition extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return BelongsTo<DeploymentOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(DeploymentOperation::class, 'deployment_operation_id');
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
