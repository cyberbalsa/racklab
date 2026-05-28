<?php

declare(strict_types=1);

namespace App\Quota;

use App\Audit\AuditEventWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Deployment;
use App\Models\DeploymentOperation;
use App\Models\Project;
use App\Models\QuotaEvent;
use App\Models\QuotaLimit;
use App\Models\QuotaReservation;
use App\Models\QuotaUsage;
use App\Models\StackDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class QuotaReservationService
{
    public function __construct(
        private AuditEventWriter $auditEvents,
        private StackQuotaEstimator $estimator,
        private QuotaScopeResolver $scopes,
        private QuotaUsageCounter $usageCounter,
    ) {}

    /**
     * @param  array<string, int>  $extraRequirements
     * @return list<QuotaReservation>
     */
    public function reserveDeploymentCreate(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        string $operationKind,
        bool $newDeployment,
        array $extraRequirements = [],
    ): array {
        $requirements = $this->mergeRequirements(
            $this->estimator->estimate($stack, $operationKind, $newDeployment),
            $extraRequirements,
        );

        if ($requirements === []) {
            return [];
        }

        $attempt = DB::transaction(fn (): QuotaReservationAttempt => $this->attemptReserve(
            actor: $actor,
            context: $context,
            project: $project,
            stack: $stack,
            operationKind: $operationKind,
            newDeployment: $newDeployment,
            requirements: $requirements,
        ));

        if ($attempt->deniedMessage !== null) {
            throw ValidationException::withMessages(['quota' => [$attempt->deniedMessage]]);
        }

        return $attempt->reservations;
    }

    /**
     * @param  array<string, int>  $base
     * @param  array<string, int>  $extra
     * @return array<string, int>
     */
    private function mergeRequirements(array $base, array $extra): array
    {
        foreach ($extra as $dimension => $quantity) {
            if ($quantity <= 0) {
                continue;
            }

            $base[$dimension] = ($base[$dimension] ?? 0) + $quantity;
        }

        return array_filter($base, static fn (int $quantity): bool => $quantity > 0);
    }

    /**
     * @param  list<QuotaReservation>  $reservations
     */
    public function attachReservations(array $reservations, Deployment $deployment, DeploymentOperation $operation): void
    {
        $ids = $this->reservationIds($reservations);

        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids, $deployment, $operation): void {
            /** @var list<QuotaReservation> $records */
            $records = QuotaReservation::query()
                ->whereKey($ids)
                ->where('state', 'reserved')
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($records as $reservation) {
                $reservation->forceFill([
                    'deployment_id' => $deployment->getKey(),
                    'deployment_operation_id' => $operation->getKey(),
                    'metadata' => [
                        ...($reservation->metadata ?? []),
                        'deployment_id' => $deployment->getKey(),
                        'deployment_operation_id' => $operation->getKey(),
                    ],
                ])->save();
            }
        });
    }

    /**
     * @param  list<QuotaReservation>  $reservations
     */
    public function releaseReservations(array $reservations, string $reason): void
    {
        $ids = $this->reservationIds($reservations);

        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids, $reason): void {
            /** @var list<QuotaReservation> $records */
            $records = QuotaReservation::query()
                ->whereKey($ids)
                ->whereIn('state', ['reserved', 'consumed'])
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($records as $reservation) {
                $this->releaseReservation($reservation, $reason);
            }
        });
    }

    public function consumeForOperation(DeploymentOperation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            /** @var list<QuotaReservation> $reservations */
            $reservations = QuotaReservation::query()
                ->where('deployment_operation_id', $operation->getKey())
                ->where('state', 'reserved')
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($reservations as $reservation) {
                $reservation->forceFill([
                    'state' => 'consumed',
                    'metadata' => [
                        ...($reservation->metadata ?? []),
                        'consumed_at' => now()->toJSON(),
                    ],
                ])->save();

                if (! QuotaUsage::query()->where('quota_reservation_id', $reservation->getKey())->exists()) {
                    QuotaUsage::query()->create([
                        'tenant_id' => $reservation->tenant_id,
                        'quota_limit_id' => $reservation->quota_limit_id,
                        'quota_reservation_id' => $reservation->getKey(),
                        'project_id' => $reservation->project_id,
                        'deployment_id' => $reservation->deployment_id,
                        'deployment_operation_id' => $reservation->deployment_operation_id,
                        'actor_user_id' => $reservation->actor_user_id,
                        'scope_type' => $reservation->scope_type,
                        'scope_id' => $reservation->scope_id,
                        'dimension' => $reservation->dimension,
                        'quantity' => $reservation->quantity,
                        'state' => 'active',
                        'metadata' => [
                            'reservation_id' => $reservation->getKey(),
                            'operation_kind' => $operation->kind,
                        ],
                    ]);
                }

                $this->recordQuotaEvent(
                    eventType: 'quota.consumed',
                    result: 'allowed',
                    reservation: $reservation,
                );
            }
        });
    }

    public function releaseForOperation(DeploymentOperation $operation, string $reason): void
    {
        $this->releaseForOperationDimensions($operation, [], $reason);
    }

    /**
     * @param  list<string>  $dimensions
     */
    public function releaseForOperationDimensions(DeploymentOperation $operation, array $dimensions, string $reason): void
    {
        DB::transaction(function () use ($operation, $dimensions, $reason): void {
            $query = QuotaReservation::query()
                ->where('deployment_operation_id', $operation->getKey())
                ->whereIn('state', ['reserved', 'consumed'])
                ->lockForUpdate();

            if ($dimensions !== []) {
                $query->whereIn('dimension', $dimensions);
            }

            /** @var list<QuotaReservation> $reservations */
            $reservations = $query->get()->all();

            foreach ($reservations as $reservation) {
                $this->releaseReservation($reservation, $reason);
            }
        });
    }

    public function releaseForDeployment(Deployment $deployment, string $reason): void
    {
        DB::transaction(function () use ($deployment, $reason): void {
            /** @var list<QuotaReservation> $reservations */
            $reservations = QuotaReservation::query()
                ->where('deployment_id', $deployment->getKey())
                ->whereIn('state', ['reserved', 'consumed'])
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($reservations as $reservation) {
                $this->releaseReservation($reservation, $reason);
            }

            /** @var list<QuotaUsage> $usages */
            $usages = QuotaUsage::query()
                ->where('deployment_id', $deployment->getKey())
                ->where('state', 'active')
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($usages as $usage) {
                $this->releaseUsage($usage, $reason);
            }
        });
    }

    /**
     * @param  array<string, int>  $requirements
     */
    private function attemptReserve(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        string $operationKind,
        bool $newDeployment,
        array $requirements,
    ): QuotaReservationAttempt {
        $approved = [];

        foreach ($requirements as $dimension => $quantity) {
            foreach ($this->limitsFor($context, $project, $actor, $dimension) as $limit) {
                $used = $this->usageCounter->usedForLimit($limit);

                if ($used + $quantity > $limit->limit_value) {
                    $message = $this->recordDenial(
                        actor: $actor,
                        context: $context,
                        project: $project,
                        stack: $stack,
                        limit: $limit,
                        operationKind: $operationKind,
                        newDeployment: $newDeployment,
                        quantity: $quantity,
                        used: $used,
                    );

                    return QuotaReservationAttempt::denied($message);
                }

                $approved[] = ['limit' => $limit, 'quantity' => $quantity];
            }
        }

        $reservations = [];

        foreach ($approved as $approval) {
            /** @var QuotaLimit $limit */
            $limit = $approval['limit'];
            $quantity = $approval['quantity'];

            /** @var QuotaReservation $reservation */
            $reservation = QuotaReservation::query()->create([
                'tenant_id' => $context->activeTenantId,
                'quota_limit_id' => $limit->getKey(),
                'project_id' => $project->getKey(),
                'actor_user_id' => $actor->getKey(),
                'scope_type' => $limit->scope_type,
                'scope_id' => $limit->scope_id,
                'dimension' => $limit->dimension,
                'quantity' => $quantity,
                'state' => 'reserved',
                'expires_at' => null,
                'metadata' => [
                    'operation_kind' => $operationKind,
                    'new_deployment' => $newDeployment,
                    'stack_definition_id' => $stack->getKey(),
                ],
            ]);

            $reservations[] = $reservation;
            $this->recordQuotaEvent(
                eventType: 'quota.reserved',
                result: 'allowed',
                reservation: $reservation,
            );
        }

        return QuotaReservationAttempt::reserved($reservations);
    }

    /**
     * @return list<QuotaLimit>
     */
    private function limitsFor(TenantContext $context, Project $project, User $actor, string $dimension): array
    {
        $scopePairs = $this->scopes->scopesFor($actor, $context, $project);

        /** @var list<QuotaLimit> $limits */
        $limits = QuotaLimit::query()
            ->where('tenant_id', $context->activeTenantId)
            ->where('dimension', $dimension)
            ->where(function (Builder $query) use ($scopePairs): void {
                $this->scopes->applyToLimitQuery($query, $scopePairs);
            })
            ->orderBy('scope_type')
            ->orderBy('scope_id')
            ->lockForUpdate()
            ->get()
            ->all();

        return $limits;
    }

    private function recordDenial(
        User $actor,
        TenantContext $context,
        Project $project,
        StackDefinition $stack,
        QuotaLimit $limit,
        string $operationKind,
        bool $newDeployment,
        int $quantity,
        int $used,
    ): string {
        $available = max(0, $limit->limit_value - $used);
        $metadata = [
            'dimension' => $limit->dimension,
            'requested' => $quantity,
            'used' => $used,
            'available' => $available,
            'limit_value' => $limit->limit_value,
            'scope_type' => $limit->scope_type,
            'scope_id' => $limit->scope_id,
            'operation_kind' => $operationKind,
            'new_deployment' => $newDeployment,
            'project_id' => $project->getKey(),
            'stack_definition_id' => $stack->getKey(),
        ];

        QuotaEvent::query()->create([
            'tenant_id' => $context->activeTenantId,
            'event_type' => 'quota.denied',
            'result' => 'denied',
            'scope_type' => $limit->scope_type,
            'scope_id' => $limit->scope_id,
            'dimension' => $limit->dimension,
            'quantity' => $quantity,
            'limit_value' => $limit->limit_value,
            'actor_user_id' => $actor->getKey(),
            'project_id' => $project->getKey(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        $this->auditEvents->append([
            'event_type' => 'quota.denied',
            'action' => 'reserve',
            'result' => 'denied',
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'actor_tenant' => $context->activeTenantId,
            'resource_type' => 'project',
            'resource_id' => $project->getKey(),
            'resource_tenant' => $project->tenant_id,
            'target_tenant_set' => [$context->activeTenantId],
            'effective_permissions' => ['deployment.create'],
            'metadata' => $metadata,
        ]);

        return sprintf(
            'Quota %s exceeded for %s scope: requested %d, available %d, limit %d.',
            $limit->dimension,
            $limit->scope_type,
            $quantity,
            $available,
            $limit->limit_value,
        );
    }

    private function releaseReservation(QuotaReservation $reservation, string $reason): void
    {
        $reservation->forceFill([
            'state' => 'released',
            'metadata' => [
                ...($reservation->metadata ?? []),
                'release_reason' => $reason,
                'released_at' => now()->toJSON(),
            ],
        ])->save();

        /** @var list<QuotaUsage> $usages */
        $usages = QuotaUsage::query()
            ->where('quota_reservation_id', $reservation->getKey())
            ->where('state', 'active')
            ->lockForUpdate()
            ->get()
            ->all();

        foreach ($usages as $usage) {
            $this->releaseUsage($usage, $reason);
        }

        $this->recordQuotaEvent(
            eventType: 'quota.released',
            result: 'allowed',
            reservation: $reservation,
        );
    }

    private function releaseUsage(QuotaUsage $usage, string $reason): void
    {
        $usage->forceFill([
            'state' => 'released',
            'metadata' => [
                ...($usage->metadata ?? []),
                'release_reason' => $reason,
                'released_at' => now()->toJSON(),
            ],
        ])->save();
    }

    private function recordQuotaEvent(string $eventType, string $result, QuotaReservation $reservation): void
    {
        QuotaEvent::query()->create([
            'tenant_id' => $reservation->tenant_id,
            'event_type' => $eventType,
            'result' => $result,
            'scope_type' => $reservation->scope_type,
            'scope_id' => $reservation->scope_id,
            'dimension' => $reservation->dimension,
            'quantity' => $reservation->quantity,
            'actor_user_id' => $reservation->actor_user_id,
            'project_id' => $reservation->project_id,
            'deployment_id' => $reservation->deployment_id,
            'deployment_operation_id' => $reservation->deployment_operation_id,
            'metadata' => [
                'quota_limit_id' => $reservation->quota_limit_id,
                'quota_reservation_id' => $reservation->getKey(),
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  list<QuotaReservation>  $reservations
     * @return list<string>
     */
    private function reservationIds(array $reservations): array
    {
        $ids = [];

        foreach ($reservations as $reservation) {
            $id = $reservation->getKey();

            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
