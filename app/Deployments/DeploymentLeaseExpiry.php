<?php

declare(strict_types=1);

namespace App\Deployments;

use App\Audit\AuditEventWriter;
use App\Broadcasting\BroadcastEventLogWriter;
use App\Models\Deployment;
use App\Models\DeploymentResource;
use App\Models\DeploymentStateTransition;
use App\Quota\QuotaReservationService;
use Illuminate\Support\Facades\DB;

final readonly class DeploymentLeaseExpiry
{
    public function __construct(
        private AuditEventWriter $auditEvents,
        private BroadcastEventLogWriter $broadcastEvents,
        private QuotaReservationService $quota,
    ) {}

    public function expireDue(): int
    {
        $expired = 0;

        /** @var Deployment $deployment */
        foreach ($this->dueDeployments() as $deployment) {
            DB::transaction(function () use ($deployment): void {
                $fromState = $deployment->state;

                DeploymentResource::query()
                    ->where('deployment_id', $deployment->getKey())
                    ->update(['state' => 'released']);

                $deployment->forceFill(['state' => 'expired'])->save();
                $this->quota->releaseForDeployment($deployment, 'lease_expired');

                DeploymentStateTransition::query()->create([
                    'tenant_id' => $deployment->tenant_id,
                    'deployment_id' => $deployment->getKey(),
                    'from_state' => $fromState,
                    'to_state' => 'expired',
                    'reason' => 'lease_expired',
                    'metadata' => [
                        'lease_expires_at' => $deployment->lease_expires_at?->toJSON(),
                    ],
                ]);

                $this->auditEvents->append([
                    'event_type' => 'deployment.lifecycle',
                    'action' => 'expired',
                    'result' => 'allowed',
                    'actor_type' => 'system',
                    'actor_id' => 'racklab.scheduler',
                    'actor_tenant' => $deployment->tenant_id,
                    'resource_type' => 'deployment',
                    'resource_id' => $deployment->getKey(),
                    'resource_tenant' => $deployment->tenant_id,
                    'target_tenant_set' => [$deployment->tenant_id],
                    'effective_permissions' => [],
                    'metadata' => [
                        'from_state' => $fromState,
                        'reason' => 'lease_expired',
                    ],
                ]);

                $this->broadcastEvents->append(
                    tenantId: $deployment->tenant_id,
                    channel: sprintf('private-tenant.%s.deployment.%s', $deployment->tenant_id, $deployment->id),
                    eventClass: 'App\\Events\\Deployments\\DeploymentExpired',
                    payload: [
                        'deployment_id' => $deployment->getKey(),
                        'state' => 'expired',
                        'from_state' => $fromState,
                        'reason' => 'lease_expired',
                    ],
                );
            });

            $expired++;
        }

        return $expired;
    }

    /**
     * @return iterable<int, Deployment>
     */
    private function dueDeployments(): iterable
    {
        return Deployment::query()
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->whereNotIn('state', ['expired', 'released'])
            ->orderBy('lease_expires_at')
            ->get();
    }
}
