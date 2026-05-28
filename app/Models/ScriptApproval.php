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
 * @property string $script_version_id
 * @property int|null $approved_by_id
 * @property string $scope_type
 * @property string|null $scope_id
 * @property string $state
 * @property \Illuminate\Support\Carbon|null $invalidated_at
 * @property string|null $invalidation_reason
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'script_id',
    'script_version_id',
    'approved_by_id',
    'scope_type',
    'scope_id',
    'state',
    'invalidated_at',
    'invalidation_reason',
    'metadata',
])]
class ScriptApproval extends Model
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
     * @return BelongsTo<ScriptVersion, $this>
     */
    public function scriptVersion(): BelongsTo
    {
        return $this->belongsTo(ScriptVersion::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'invalidated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
