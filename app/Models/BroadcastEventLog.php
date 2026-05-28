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
 * @property string $channel
 * @property string $event_class
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable([
    'id',
    'tenant_id',
    'channel',
    'event_class',
    'payload',
    'created_at',
])]
class BroadcastEventLog extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var string
     */
    protected $table = 'broadcast_event_log';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
