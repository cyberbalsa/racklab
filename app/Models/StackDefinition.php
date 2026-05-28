<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantScopedResource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $project_id
 * @property string $name
 * @property string $slug
 * @property string $scope
 * @property bool $is_reserved_default
 * @property array<string, mixed>|null $definition
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'name',
    'slug',
    'scope',
    'is_reserved_default',
    'definition',
    'sharing_scope',
    'shared_with_tenants',
])]
class StackDefinition extends Model implements TenantScopedResource
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'stack_definition';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'is_reserved_default' => 'boolean',
            'shared_with_tenants' => 'array',
        ];
    }
}
