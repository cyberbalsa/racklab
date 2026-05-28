<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Tenancy\SharingScope;
use App\Domain\Tenancy\TenantContextStore;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope(app(TenantContextStore::class)));

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $context = app(TenantContextStore::class)->current();

            if ($context !== null) {
                $model->setAttribute('tenant_id', $context->activeTenantId);
            }
        });
    }

    public function tenantId(): string
    {
        $tenantId = $this->getAttribute('tenant_id');

        if (! is_string($tenantId) || trim($tenantId) === '') {
            throw new InvalidArgumentException('Tenant-scoped models require a non-empty tenant_id attribute.');
        }

        return $tenantId;
    }

    public function resourceType(): string
    {
        $resourceType = $this->tenantResourceTypeName();

        if (trim((string) $resourceType) === '') {
            throw new InvalidArgumentException('Tenant-scoped models require a non-empty resource type.');
        }

        return $resourceType;
    }

    protected function tenantResourceTypeName(): string
    {
        return $this->getTable();
    }

    public function resourceId(): string
    {
        $resourceId = $this->getKey();

        if (! is_int($resourceId) && ! is_string($resourceId)) {
            throw new InvalidArgumentException('Tenant-scoped models require an int or string primary key.');
        }

        return (string) $resourceId;
    }

    public function sharingScope(): SharingScope
    {
        $sharingScope = $this->getAttribute('sharing_scope');

        if (is_string($sharingScope)) {
            return SharingScope::tryFrom($sharingScope) ?? SharingScope::TenantLocal;
        }

        return SharingScope::TenantLocal;
    }

    /**
     * @return list<string>
     */
    public function sharedWithTenantIds(): array
    {
        $sharedWithTenants = $this->getAttribute('shared_with_tenants');

        if (is_string($sharedWithTenants)) {
            $decoded = json_decode($sharedWithTenants, associative: true);
            $sharedWithTenants = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($sharedWithTenants)) {
            return [];
        }

        return array_values(array_filter(
            $sharedWithTenants,
            static fn (mixed $tenantId): bool => is_string($tenantId) && trim($tenantId) !== '',
        ));
    }
}
