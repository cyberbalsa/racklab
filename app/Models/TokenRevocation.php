<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $token_grant_id
 * @property int|null $revoked_by_id
 * @property string $reason
 * @property \Illuminate\Support\Carbon $revoked_at
 */
#[Fillable([
    'tenant_id',
    'token_grant_id',
    'revoked_by_id',
    'reason',
    'revoked_at',
])]
class TokenRevocation extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<TokenGrant, $this>
     */
    public function tokenGrant(): BelongsTo
    {
        return $this->belongsTo(TokenGrant::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }
}
