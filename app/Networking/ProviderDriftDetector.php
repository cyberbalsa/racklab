<?php

declare(strict_types=1);

namespace App\Networking;

use App\Audit\AuditEventWriter;
use App\Models\ProviderDrift;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ProviderDriftDetector
{
    public function __construct(
        private ProviderStateSnapshotter $snapshotter,
        private ProviderDriftDiffer $differ,
        private AuditEventWriter $auditEvents,
    ) {}

    public function detect(?string $tenant = null, ?string $provider = null): int
    {
        $tenantId = $this->tenantId($tenant);
        $count = 0;

        foreach ($this->snapshotter->resources($tenantId, $provider) as $resource) {
            $expected = $this->snapshotter->expectedState($resource);
            $observed = $this->snapshotter->observedState($resource);
            $drift = $this->differ->diff($expected, $observed);

            if ($drift === []) {
                continue;
            }

            $this->record($resource, $expected, $observed, $drift);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $observed
     * @param  list<array{path: string, expected: mixed, observed: mixed}>  $drift
     */
    private function record(Model $resource, array $expected, array $observed, array $drift): ProviderDrift
    {
        return DB::transaction(function () use ($resource, $expected, $observed, $drift): ProviderDrift {
            $tenantId = $resource->getAttribute('tenant_id');
            $key = $resource->getKey();

            if (! is_string($tenantId) || (! is_string($key) && ! is_int($key))) {
                throw new InvalidArgumentException('Provider drift resources require string tenant and resource ids.');
            }

            $resourceType = $this->snapshotter->resourceType($resource);
            $resourceId = (string) $key;

            /** @var ProviderDrift|null $providerDrift */
            $providerDrift = ProviderDrift::query()
                ->where('tenant_id', $tenantId)
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->where('state', 'detected')
                ->first();

            $attributes = [
                'tenant_id' => $tenantId,
                'project_id' => $this->snapshotter->projectId($resource),
                'provider' => $this->snapshotter->provider($resource),
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'resource_label' => $this->snapshotter->label($resource),
                'state' => 'detected',
                'expected_state' => $expected,
                'observed_state' => $observed,
                'drift' => $drift,
                'detected_at' => now(),
                'resolved_at' => null,
                'resolved_by_id' => null,
                'resolution' => null,
                'metadata' => [
                    'source' => 'provider-drift-detector',
                ],
            ];

            if ($providerDrift instanceof ProviderDrift) {
                $providerDrift->forceFill($attributes)->save();
            } else {
                /** @var ProviderDrift $providerDrift */
                $providerDrift = ProviderDrift::query()->create($attributes);
            }

            $this->audit($providerDrift, 'detected', 'allowed', [
                'drift' => $drift,
            ]);

            return $providerDrift;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(ProviderDrift $drift, string $action, string $result, array $metadata): void
    {
        $this->auditEvents->append([
            'event_type' => 'provider.drift',
            'action' => $action,
            'result' => $result,
            'actor_type' => 'system',
            'actor_id' => 'racklab:reconciler',
            'actor_tenant' => $drift->tenant_id,
            'resource_type' => $drift->resource_type,
            'resource_id' => $drift->resource_id,
            'resource_tenant' => $drift->tenant_id,
            'target_tenant_set' => [$drift->tenant_id],
            'effective_permissions' => [],
            'metadata' => [
                'provider_drift_id' => $drift->getKey(),
                'provider' => $drift->provider,
                ...$metadata,
            ],
        ]);
    }

    private function tenantId(?string $tenant): ?string
    {
        if (! is_string($tenant) || $tenant === '') {
            return null;
        }

        /** @var Tenant|null $model */
        $model = Tenant::query()
            ->whereKey($tenant)
            ->orWhere('slug', $tenant)
            ->first();

        $key = $model?->getKey();

        return is_string($key) ? $key : null;
    }
}
