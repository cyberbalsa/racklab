<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\RoleBindingScopeType;
use App\Domain\Tenancy\TenantScopedResource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;
use Override;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int $owner_user_id
 * @property int $created_by_id
 * @property int|null $revoked_by_id
 * @property int|null $sanctum_token_id
 * @property string|null $jti
 * @property string $name
 * @property string $track
 * @property RoleBindingScopeType $scope_type
 * @property list<string>|null $tenant_set
 * @property string $resource_type
 * @property string $resource_id
 * @property list<string> $abilities
 * @property list<string>|null $allowed_ip_cidrs
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
#[Fillable([
    'tenant_id',
    'owner_user_id',
    'created_by_id',
    'revoked_by_id',
    'sanctum_token_id',
    'jti',
    'name',
    'track',
    'scope_type',
    'tenant_set',
    'resource_type',
    'resource_id',
    'abilities',
    'allowed_ip_cidrs',
    'expires_at',
    'last_used_at',
    'revoked_at',
])]
class TokenGrant extends Model implements TenantScopedResource
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
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function sanctumToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'sanctum_token_id');
    }

    protected function tenantResourceTypeName(): string
    {
        return 'token_grant';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'allowed_ip_cidrs' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'scope_type' => RoleBindingScopeType::class,
            'tenant_set' => 'array',
        ];
    }
}
