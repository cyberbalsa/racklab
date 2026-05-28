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
 * @property string $script_id
 * @property int|null $created_by_id
 * @property int $version_number
 * @property list<string> $command
 * @property string $source
 * @property string $executable_hash
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'script_id',
    'created_by_id',
    'version_number',
    'command',
    'source',
    'executable_hash',
    'metadata',
])]
class ScriptVersion extends Model
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
     * @return BelongsTo<Script, $this>
     */
    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'command' => 'array',
            'metadata' => 'array',
        ];
    }
}
