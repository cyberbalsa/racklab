<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int|null $actor_user_id
 * @property string|null $project_id
 * @property string|null $script_id
 * @property string|null $script_version_id
 * @property string|null $deployment_id
 * @property string|null $deployment_resource_id
 * @property string $runner_kind
 * @property string $state
 * @property list<string> $command
 * @property string|null $source
 * @property string|null $stdout
 * @property string|null $stderr
 * @property int|null $exit_code
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'tenant_id',
    'actor_user_id',
    'project_id',
    'script_id',
    'script_version_id',
    'deployment_id',
    'deployment_resource_id',
    'runner_kind',
    'state',
    'command',
    'source',
    'stdout',
    'stderr',
    'exit_code',
    'started_at',
    'finished_at',
    'metadata',
])]
class ScriptRun extends Model
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
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'command' => 'array',
            'exit_code' => 'integer',
            'finished_at' => 'datetime',
            'metadata' => 'array',
            'started_at' => 'datetime',
        ];
    }
}
