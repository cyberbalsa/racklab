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
 * @property int $version_number
 * @property string $markdown_source
 * @property string|null $html_cache
 * @property int|null $author_user_id
 * @property string|null $editor_message
 */
#[Fillable([
    'tenant_id',
    'doc_id',
    'version_number',
    'markdown_source',
    'html_cache',
    'author_user_id',
    'editor_message',
])]
class DocVersion extends Model implements TenantScopedResource
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    protected function tenantResourceTypeName(): string
    {
        return 'doc_version';
    }
}
