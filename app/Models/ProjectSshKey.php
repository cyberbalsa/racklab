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
 * @property string $project_id
 * @property int|null $created_by_id
 * @property string $name
 * @property string $key_type
 * @property string $public_key
 * @property string $fingerprint
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'created_by_id',
    'name',
    'key_type',
    'public_key',
    'fingerprint',
    'metadata',
])]
class ProjectSshKey extends Model
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
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
