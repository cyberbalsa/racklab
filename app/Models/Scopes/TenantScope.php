<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
final readonly class TenantScope implements Scope
{
    public function __construct(private TenantContextStore $tenantContext) {}

    public function apply(Builder $builder, Model $model): void
    {
        $context = $this->tenantContext->current();

        if (! $context instanceof TenantContext) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->activeTenantId);
    }
}
