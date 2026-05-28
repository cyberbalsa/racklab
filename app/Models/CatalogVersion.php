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
 * @property string $catalog_item_id
 * @property string $stack_definition_id
 * @property string $version
 * @property string $state
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property string|null $summary
 */
#[Fillable([
    'tenant_id',
    'catalog_item_id',
    'stack_definition_id',
    'version',
    'state',
    'published_at',
    'summary',
])]
class CatalogVersion extends Model
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
     * @return BelongsTo<CatalogItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    /**
     * @return BelongsTo<StackDefinition, $this>
     */
    public function stackDefinition(): BelongsTo
    {
        return $this->belongsTo(StackDefinition::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
