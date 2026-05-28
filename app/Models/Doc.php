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
 * @property string|null $project_id
 * @property string|null $course_id
 * @property int|null $owner_user_id
 * @property string $slug
 * @property string $title
 * @property string|null $current_version_id
 * @property string $sharing_scope
 * @property list<string>|null $shared_with_tenants
 * @property \Illuminate\Support\Carbon|null $published_at
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'course_id',
    'owner_user_id',
    'slug',
    'title',
    'current_version_id',
    'sharing_scope',
    'shared_with_tenants',
    'published_at',
])]
class Doc extends Model implements TenantScopedResource
{
    use BelongsToTenant;
    use HasUlids;

    public $incrementing = false;

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

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<DocVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocVersion::class)->orderByDesc('version_number');
    }

    /**
     * @return BelongsTo<DocVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<DocImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(DocImage::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'doc';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'shared_with_tenants' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
