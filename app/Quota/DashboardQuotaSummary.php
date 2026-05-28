<?php

declare(strict_types=1);

namespace App\Quota;

use App\Domain\Tenancy\TenantContext;
use App\Models\Project;
use App\Models\QuotaLimit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final readonly class DashboardQuotaSummary
{
    /**
     * @var list<string>
     */
    private const array DIMENSIONS = ['vcpu', 'concurrent_deployments'];

    public function __construct(
        private QuotaScopeResolver $scopes,
        private QuotaUsageCounter $usageCounter,
    ) {}

    /**
     * @param  list<Project>  $projects
     * @return array<string, list<array{dimension: string, label_key: string, used: int, limit: int, percent: int, scope_type: string, scope_id: string}>>
     */
    public function forProjects(User $actor, TenantContext $context, array $projects): array
    {
        $summaries = [];

        foreach ($projects as $project) {
            /** @var list<array{dimension: string, label_key: string, used: int, limit: int, percent: int, scope_type: string, scope_id: string}> $projectSummary */
            $projectSummary = [];

            foreach (self::DIMENSIONS as $dimension) {
                $limit = $this->bottleneckLimit($actor, $context, $project, $dimension);

                if (! $limit instanceof QuotaLimit) {
                    continue;
                }

                $used = $this->usageCounter->usedForLimit($limit);
                $limitValue = $limit->limit_value;
                $projectSummary[] = [
                    'dimension' => $dimension,
                    'label_key' => 'racklab.dashboard.quota_'.$dimension,
                    'used' => $used,
                    'limit' => $limitValue,
                    'percent' => $limitValue > 0 ? min(100, (int) floor(($used / $limitValue) * 100)) : 100,
                    'scope_type' => $limit->scope_type,
                    'scope_id' => $limit->scope_id,
                ];
            }

            $summaries[$project->id] = $projectSummary;
        }

        return $summaries;
    }

    private function bottleneckLimit(User $actor, TenantContext $context, Project $project, string $dimension): ?QuotaLimit
    {
        $limits = $this->limitsFor($actor, $context, $project, $dimension);
        $best = null;
        $bestAvailable = null;

        foreach ($limits as $limit) {
            $available = $limit->limit_value - $this->usageCounter->usedForLimit($limit);

            if (
                ! $best instanceof QuotaLimit
                || $bestAvailable === null
                || $available < $bestAvailable
                || ($available === $bestAvailable && $limit->limit_value < $best->limit_value)
            ) {
                $best = $limit;
                $bestAvailable = $available;
            }
        }

        return $best;
    }

    /**
     * @return list<QuotaLimit>
     */
    private function limitsFor(User $actor, TenantContext $context, Project $project, string $dimension): array
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
            ->get()
            ->all();

        return $limits;
    }
}
