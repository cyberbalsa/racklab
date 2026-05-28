<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Audit\AuditEventWriter;
use App\Domain\Rbac\Permission;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class CrossTenantFetch
{
    public function __construct(
        private AccessResolver $accessResolver,
        private AuditEventWriter $auditEvents,
    ) {}

    /**
     * @template TResource of Model&TenantScopedResource
     *
     * @param  class-string<TResource>  $modelClass
     * @param  array<string, scalar|null|list<scalar|null>>  $filters
     * @return list<CrossTenantFetchResult<TResource>>
     */
    public function resolveForFetch(
        ActorIdentity $actor,
        Permission $permission,
        string $modelClass,
        array $filters,
        TenantContext $context,
    ): array {
        $prototype = new $modelClass;

        if (! $prototype instanceof Model) {
            throw new InvalidArgumentException('CrossTenantFetch requires an Eloquent model class.');
        }

        $query = $prototype->newQuery()->withoutGlobalScope(TenantScope::class);

        foreach ($filters as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);

                continue;
            }

            $query->where($column, $value);
        }

        $results = [];

        /** @var Model $resource */
        foreach ($query->get() as $resource) {
            if (! $resource instanceof TenantScopedResource) {
                throw new InvalidArgumentException('CrossTenantFetch resources must implement TenantScopedResource.');
            }

            /** @var TResource $resource */
            $decision = $this->accessResolver->permitted($actor, $permission, $resource, $context);

            if ($resource->tenantId() !== $context->activeTenantId) {
                $this->auditCrossTenantFetch($actor, $permission, $resource, $context, $decision);
            }

            if (! $decision->allowed) {
                continue;
            }

            $results[] = new CrossTenantFetchResult($resource, $decision->provenance);
        }

        return $results;
    }

    private function auditCrossTenantFetch(
        ActorIdentity $actor,
        Permission $permission,
        TenantScopedResource $resource,
        TenantContext $context,
        AccessDecision $decision,
    ): void {
        $this->auditEvents->append([
            'event_type' => 'tenant.cross_access',
            'action' => $permission->code,
            'result' => $decision->allowed ? 'allowed' : 'denied',
            'actor_type' => 'user',
            'actor_id' => $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => $resource->resourceType(),
            'resource_id' => $resource->resourceId(),
            'resource_tenant' => $resource->tenantId(),
            'target_tenant_set' => [],
            'metadata' => [
                ...$this->crossTenantMetadata($decision->provenance),
                'provenance' => $decision->provenance,
                'reason' => $decision->denyReason instanceof AccessDenyReason
                    ? $decision->denyReason->value
                    : 'allowed',
            ],
        ]);
    }

    /**
     * @param  list<string>  $provenance
     * @return array<string, string>
     */
    private function crossTenantMetadata(array $provenance): array
    {
        $metadata = [];

        foreach ($provenance as $entry) {
            $parts = explode(':', $entry);

            if ($parts[0] === 'binding' && count($parts) === 3) {
                $metadata['binding_id'] = $parts[1];
                $metadata['binding_scope'] = $parts[2];
            }

            if ($parts[0] === 'sharing' && count($parts) === 3) {
                $metadata['sharing_scope'] = $parts[1];
                $metadata['shared_resource_owner_tenant'] = $parts[2];
            }
        }

        return $metadata;
    }
}
