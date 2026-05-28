<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\Untenanted;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string|null $tenant_id
 * @property string $jti
 * @property int|null $revoked_by_id
 * @property string $reason
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon $revoked_at
 */
#[Untenanted(reason: 'JWT jti blacklist is a global lookup table')]
#[Fillable([
    'tenant_id',
    'jti',
    'revoked_by_id',
    'reason',
    'expires_at',
    'revoked_at',
])]
class JwtRevocation extends Model
{
    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
