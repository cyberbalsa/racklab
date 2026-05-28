<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\Untenanted;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $event_type
 * @property string $action
 * @property string $result
 * @property string $actor_type
 * @property string $actor_id
 * @property string $actor_tenant
 * @property string $resource_type
 * @property string|null $resource_id
 * @property string|null $resource_tenant
 * @property list<string> $target_tenant_set
 * @property list<string>|null $effective_permissions
 * @property string|null $request_id
 * @property string|null $correlation_id
 * @property string|null $source_ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property string|null $prev_hash
 * @property string $hash
 */
#[Untenanted(reason: 'three-tenant schema, see redesign spec section 5')]
#[Fillable([
    'event_type',
    'action',
    'result',
    'actor_type',
    'actor_id',
    'actor_tenant',
    'resource_type',
    'resource_id',
    'resource_tenant',
    'target_tenant_set',
    'effective_permissions',
    'request_id',
    'correlation_id',
    'source_ip',
    'user_agent',
    'metadata',
    'occurred_at',
    'prev_hash',
    'hash',
])]
class AuditEvent extends Model
{
    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where(function (Builder $query) use ($tenantId): void {
            $query
                ->where('actor_tenant', $tenantId)
                ->orWhere('resource_tenant', $tenantId)
                ->orWhereJsonContains('target_tenant_set', $tenantId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function hashPayload(): array
    {
        return [
            'action' => $this->action,
            'actor_id' => $this->actor_id,
            'actor_tenant' => $this->actor_tenant,
            'actor_type' => $this->actor_type,
            'correlation_id' => $this->correlation_id,
            'effective_permissions' => $this->effective_permissions ?? [],
            'event_type' => $this->event_type,
            'metadata' => $this->metadata ?? [],
            'occurred_at' => $this->occurred_at->toJSON(),
            'request_id' => $this->request_id,
            'resource_id' => $this->resource_id,
            'resource_tenant' => $this->resource_tenant,
            'resource_type' => $this->resource_type,
            'result' => $this->result,
            'source_ip' => $this->source_ip,
            'target_tenant_set' => $this->target_tenant_set,
            'user_agent' => $this->user_agent,
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'effective_permissions' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'target_tenant_set' => 'array',
        ];
    }
}
