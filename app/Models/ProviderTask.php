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
 * @property string $deployment_operation_id
 * @property string $provider
 * @property string $provider_task_id
 * @property string|null $upid
 * @property string|null $proxmox_node
 * @property int|null $proxmox_pid
 * @property string|null $proxmox_pstart
 * @property int|null $proxmox_starttime
 * @property string|null $proxmox_type
 * @property string|null $proxmox_vm_id
 * @property string|null $proxmox_user
 * @property string|null $idempotency_key
 * @property string|null $operation_class
 * @property string $action
 * @property string $state
 * @property int $attempts
 * @property int $attempt_count
 * @property \Illuminate\Support\Carbon|null $lease_expires_at
 * @property \Illuminate\Support\Carbon|null $last_polled_at
 * @property \Illuminate\Support\Carbon|null $deadline_at
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'deployment_id',
    'deployment_operation_id',
    'provider',
    'provider_task_id',
    'upid',
    'proxmox_node',
    'proxmox_pid',
    'proxmox_pstart',
    'proxmox_starttime',
    'proxmox_type',
    'proxmox_vm_id',
    'proxmox_user',
    'idempotency_key',
    'operation_class',
    'action',
    'state',
    'attempts',
    'attempt_count',
    'lease_expires_at',
    'last_polled_at',
    'deadline_at',
    'error_message',
    'metadata',
])]
class ProviderTask extends Model
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
            'attempt_count' => 'integer',
            'attempts' => 'integer',
            'deadline_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'metadata' => 'array',
            'proxmox_pid' => 'integer',
            'proxmox_starttime' => 'integer',
        ];
    }
}
