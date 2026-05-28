<?php

declare(strict_types=1);

namespace App\Scheduling;

use App\Domain\Tenancy\TenantContext;
use App\Models\ProviderCapacitySnapshot;
use Illuminate\Validation\ValidationException;

final readonly class ProviderScheduler
{
    public function schedule(TenantContext $context, PlacementRequest $request): PlacementDecision
    {
        /** @var list<ProviderCapacitySnapshot> $snapshots */
        $snapshots = ProviderCapacitySnapshot::query()
            ->where('tenant_id', $context->activeTenantId)
            ->where('provider', $request->provider)
            ->where('healthy', true)
            ->where('maintenance_mode', false)
            ->when($request->providerCluster !== null, static function ($query) use ($request): void {
                $query->where('provider_cluster', $request->providerCluster);
            })
            ->get()
            ->all();

        $candidates = array_values(array_filter(
            $snapshots,
            fn (ProviderCapacitySnapshot $snapshot): bool => $this->hasCapacity($snapshot, $request)
                && $this->hasTags($snapshot, $request->requiredTags)
                && $this->satisfiesAntiAffinity($snapshot, $request->antiAffinityExcludedNodes),
        ));

        if ($candidates === []) {
            throw ValidationException::withMessages([
                'placement' => ['No eligible provider node has enough capacity for this deployment.'],
            ]);
        }

        $candidateNodes = array_map(
            static fn (ProviderCapacitySnapshot $snapshot): string => $snapshot->node,
            $candidates,
        );
        sort($candidateNodes);

        usort($candidates, fn (ProviderCapacitySnapshot $left, ProviderCapacitySnapshot $right): int => $this->compareCandidates($left, $right, $request));

        $selected = $candidates[0];

        return new PlacementDecision(
            provider: $request->provider,
            node: $selected->node,
            candidateNodes: $candidateNodes,
            reasons: $this->reasons($selected, $request),
        );
    }

    private function hasCapacity(ProviderCapacitySnapshot $snapshot, PlacementRequest $request): bool
    {
        return $snapshot->available_vcpus >= $request->requiredVcpus
            && $snapshot->available_memory_mb >= $request->requiredMemoryMb
            && $snapshot->available_storage_gb >= $request->requiredStorageGb;
    }

    /**
     * @param  list<string>  $requiredTags
     */
    private function hasTags(ProviderCapacitySnapshot $snapshot, array $requiredTags): bool
    {
        if ($requiredTags === []) {
            return true;
        }

        $tags = $snapshot->tags ?? [];

        foreach ($requiredTags as $requiredTag) {
            if (! in_array($requiredTag, $tags, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $excludedNodes
     */
    private function satisfiesAntiAffinity(ProviderCapacitySnapshot $snapshot, array $excludedNodes): bool
    {
        return ! in_array($snapshot->node, $excludedNodes, true);
    }

    private function compareCandidates(ProviderCapacitySnapshot $left, ProviderCapacitySnapshot $right, PlacementRequest $request): int
    {
        return $this->templateScore($right, $request) <=> $this->templateScore($left, $request)
            ?: $left->job_pressure <=> $right->job_pressure
            ?: $right->available_memory_mb <=> $left->available_memory_mb
            ?: $right->available_storage_gb <=> $left->available_storage_gb
            ?: $right->available_vcpus <=> $left->available_vcpus
            ?: $left->node <=> $right->node;
    }

    private function templateScore(ProviderCapacitySnapshot $snapshot, PlacementRequest $request): int
    {
        if ($request->templateVmid === null) {
            return 0;
        }

        return in_array($request->templateVmid, $snapshot->templates ?? [], true) ? 1 : 0;
    }

    /**
     * @return list<string>
     */
    private function reasons(ProviderCapacitySnapshot $snapshot, PlacementRequest $request): array
    {
        $reasons = ['healthy', 'capacity'];

        if ($this->templateScore($snapshot, $request) > 0) {
            $reasons[] = 'template_locality';
        }

        if (($snapshot->tags ?? []) !== []) {
            $reasons[] = 'tags';
        }

        if ($request->antiAffinityExcludedNodes !== []) {
            $reasons[] = 'anti_affinity';
        }

        return $reasons;
    }
}
