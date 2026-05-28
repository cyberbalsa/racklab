<?php

declare(strict_types=1);

namespace App\Networking;

use App\Audit\AuditEventWriter;
use App\Models\ProviderDrift;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final readonly class ProviderDriftResolver
{
    public function __construct(
        private ProviderStateSnapshotter $snapshotter,
        private AuditEventWriter $auditEvents,
    ) {}

    public function repair(ProviderDrift $drift, ?User $actor = null): ProviderDrift
    {
        return DB::transaction(function () use ($drift, $actor): ProviderDrift {
            $resource = $this->snapshotter->findResource($drift);

            if (! $resource instanceof Model) {
                throw new ModelNotFoundException;
            }

            $this->snapshotter->setObservedState($resource, $this->snapshotter->expectedState($resource));
            $this->resolve($drift, 'repaired', 'repair', $actor);
            $this->audit($drift, 'repaired', 'allowed', $actor);

            return $drift->refresh();
        });
    }

    public function adopt(ProviderDrift $drift, ?User $actor = null): ProviderDrift
    {
        return DB::transaction(function () use ($drift, $actor): ProviderDrift {
            $resource = $this->snapshotter->findResource($drift);

            if (! $resource instanceof Model) {
                throw new ModelNotFoundException;
            }

            $this->snapshotter->applyObservedState($resource, $drift->observed_state);
            $this->resolve($drift, 'adopted', 'adopt', $actor);
            $this->audit($drift, 'adopted', 'allowed', $actor);

            return $drift->refresh();
        });
    }

    private function resolve(ProviderDrift $drift, string $state, string $resolution, ?User $actor): void
    {
        $drift->forceFill([
            'state' => $state,
            'resolved_at' => now(),
            'resolved_by_id' => $actor?->getKey(),
            'resolution' => $resolution,
        ])->save();
    }

    private function audit(ProviderDrift $drift, string $action, string $result, ?User $actor): void
    {
        $this->auditEvents->append([
            'event_type' => 'provider.drift',
            'action' => $action,
            'result' => $result,
            'actor_type' => $actor instanceof User ? 'user' : 'system',
            'actor_id' => $actor instanceof User ? (string) $actor->id : 'racklab:reconciler',
            'actor_tenant' => $drift->tenant_id,
            'resource_type' => $drift->resource_type,
            'resource_id' => $drift->resource_id,
            'resource_tenant' => $drift->tenant_id,
            'target_tenant_set' => [$drift->tenant_id],
            'effective_permissions' => ['network.attach_provider'],
            'metadata' => [
                'provider_drift_id' => $drift->getKey(),
                'provider' => $drift->provider,
                'resolution' => $drift->resolution,
            ],
        ]);
    }
}
