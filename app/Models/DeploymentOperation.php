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
 * @property string $project_id
 * @property string $stack_definition_id
 * @property int $actor_user_id
 * @property string $kind
 * @property string $idempotency_key
 * @property string $state
 * @property array<string, mixed>|null $requested_diff
 * @property array<string, mixed>|null $result
 * @property string|null $error_message
 */
#[Fillable([
    'tenant_id',
    'deployment_id',
    'project_id',
    'stack_definition_id',
    'actor_user_id',
    'kind',
    'idempotency_key',
    'state',
    'requested_diff',
    'result',
    'error_message',
])]
class DeploymentOperation extends Model
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
            'requested_diff' => 'array',
            'result' => 'array',
        ];
    }
}
