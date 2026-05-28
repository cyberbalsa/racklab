<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $content_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $storage_disk
 * @property string $storage_path
 * @property bool $quarantined
 * @property string|null $owner_scope_type
 * @property string|null $owner_scope_id
 * @property string $rbac_visibility
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'kind',
    'content_type',
    'size_bytes',
    'sha256',
    'storage_disk',
    'storage_path',
    'quarantined',
    'owner_scope_type',
    'owner_scope_id',
    'rbac_visibility',
    'metadata',
])]
class Artifact extends Model
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
     * @return HasMany<ArtifactReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(ArtifactReference::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'quarantined' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }
}
