<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\RoleBindingRecord;
use App\Domain\Tenancy\RoleBindingScopeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $principal_type
 * @property string $principal_id
 * @property string $role
 * @property string $resource_type
 * @property string $resource_id
 * @property RoleBindingScopeType $scope_type
 * @property string|null $tenant_id
 * @property list<string>|null $tenant_set
 * @property int|null $granted_by_id
 * @property string|null $granted_reason
 */
#[Fillable([
    'principal_type',
    'principal_id',
    'role',
    'resource_type',
    'resource_id',
    'scope_type',
    'tenant_id',
    'tenant_set',
    'granted_by_id',
    'granted_reason',
])]
class RoleBinding extends Model
{
    use HasUlids;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    public function toRecord(): RoleBindingRecord
    {
        return new RoleBindingRecord(
            id: $this->id,
            principalId: $this->principal_id,
            role: $this->role,
            scopeType: $this->scope_type,
            tenantId: $this->tenant_id,
            tenantSet: $this->tenantSet(),
            resourceType: $this->resource_type,
            resourceId: $this->resource_id,
        );
    }

    /**
     * @return list<string>
     */
    private function tenantSet(): array
    {
        return array_values(array_filter(
            $this->tenant_set ?? [],
            static fn (mixed $tenantId): bool => is_string($tenantId) && trim($tenantId) !== '',
        ));
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'scope_type' => RoleBindingScopeType::class,
            'tenant_set' => 'array',
        ];
    }
}
