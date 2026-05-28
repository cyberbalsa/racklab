<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domain\Tenancy\TenantScopedResource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property string $sharing_scope
 * @property list<string> $shared_with_tenants
 */
#[Fillable(['tenant_id', 'name', 'sharing_scope', 'shared_with_tenants'])]
final class TenantScopedWidget extends Model implements TenantScopedResource
{
    use BelongsToTenant;

    /**
     * @var string
     */
    protected $table = 'tenant_scoped_widgets';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'shared_with_tenants' => 'array',
        ];
    }

    protected function tenantResourceTypeName(): string
    {
        return 'tenant_scoped_widget';
    }
}
