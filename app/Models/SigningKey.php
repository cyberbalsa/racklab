<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\Untenanted;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $kid
 * @property string $algorithm
 * @property string $status
 * @property string $public_key_pem
 * @property string|null $private_key_pem
 * @property \Illuminate\Support\Carbon|null $not_before
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
#[Untenanted(reason: 'platform signing keys are global')]
#[Fillable([
    'kid',
    'algorithm',
    'status',
    'public_key_pem',
    'private_key_pem',
    'not_before',
    'expires_at',
    'revoked_at',
])]
#[Hidden(['private_key_pem'])]
class SigningKey extends Model
{
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
            'expires_at' => 'datetime',
            'not_before' => 'datetime',
            'private_key_pem' => 'encrypted',
            'revoked_at' => 'datetime',
        ];
    }
}
