<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\Untenanted;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Override;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 */
#[Untenanted(reason: 'top-level tenant root')]
#[Fillable(['name', 'slug', 'is_active'])]
class Tenant extends Model implements IsTenant
{
    use HasUlids;
    use ImplementsTenant;
    use UsesLandlordConnection;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    public function getDatabaseName(): string
    {
        $connection = config('database.default');

        if (! is_string($connection)) {
            return '';
        }

        $database = config(sprintf('database.connections.%s.database', $connection));

        return is_string($database) ? $database : '';
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
