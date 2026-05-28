<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantScopedResource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $doc_id
 * @property string|null $artifact_id
 * @property string $content_type
 * @property int $size_bytes
 * @property string|null $sha256
 * @property int|null $uploaded_by_id
 */
#[Fillable([
    'tenant_id',
    'doc_id',
    'artifact_id',
    'content_type',
    'size_bytes',
    'sha256',
    'uploaded_by_id',
])]
class DocImage extends Model implements TenantScopedResource
{
    use BelongsToTenant;
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<Doc, $this>
     */
    public function doc(): BelongsTo
    {
        return $this->belongsTo(Doc::class);
    }

    /**
     * @return BelongsTo<Artifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    protected function tenantResourceTypeName(): string
    {
        return 'doc_image';
    }
}
