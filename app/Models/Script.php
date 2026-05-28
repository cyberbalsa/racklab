<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantScopedResource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property int|null $owner_user_id
 * @property string $name
 * @property string $slug
 * @property string $runner_kind
 * @property string|null $current_version_id
 * @property string $state
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'owner_user_id',
    'name',
    'slug',
    'runner_kind',
    'current_version_id',
    'state',
    'sharing_scope',
    'shared_with_tenants',
    'metadata',
])]
class Script extends Model implements TenantScopedResource
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ScriptVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ScriptVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<ScriptVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ScriptVersion::class);
    }

    /**
     * @return HasMany<ScriptApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(ScriptApproval::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'script';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'shared_with_tenants' => 'array',
        ];
    }
}
