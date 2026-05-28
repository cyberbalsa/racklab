<?php

declare(strict_types=1);

namespace App\Quota;

use App\Domain\Tenancy\TenantContext;
use App\Models\CourseMembership;
use App\Models\Project;
use App\Models\QuotaLimit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final readonly class QuotaScopeResolver
{
    /**
     * @return list<array{type: string, ids: list<string>}>
     */
    public function scopesFor(User $actor, TenantContext $context, Project $project): array
    {
        $scopes = [
            ['type' => 'tenant', 'ids' => ['*', $context->activeTenantId]],
            ['type' => 'project', 'ids' => [$project->id]],
            ['type' => 'user', 'ids' => [(string) $actor->id]],
        ];

        $courseIds = $this->courseIdsFor($actor, $context);

        if ($courseIds !== []) {
            $scopes[] = ['type' => 'course', 'ids' => $courseIds];
        }

        return $scopes;
    }

    /**
     * @param  Builder<QuotaLimit>  $query
     * @param  list<array{type: string, ids: list<string>}>  $scopePairs
     */
    public function applyToLimitQuery(Builder $query, array $scopePairs): void
    {
        $first = true;

        foreach ($scopePairs as $scopePair) {
            if ($scopePair['ids'] === []) {
                continue;
            }

            if ($first) {
                $query->where(function (Builder $query) use ($scopePair): void {
                    $query
                        ->where('scope_type', $scopePair['type'])
                        ->whereIn('scope_id', $scopePair['ids']);
                });
                $first = false;

                continue;
            }

            $query->orWhere(function (Builder $query) use ($scopePair): void {
                $query
                    ->where('scope_type', $scopePair['type'])
                    ->whereIn('scope_id', $scopePair['ids']);
            });
        }
    }

    /**
     * @return list<string>
     */
    private function courseIdsFor(User $actor, TenantContext $context): array
    {
        $courseIds = CourseMembership::query()
            ->where('tenant_id', $context->activeTenantId)
            ->where('user_id', $actor->id)
            ->pluck('course_id')
            ->all();

        return array_values(array_filter(
            $courseIds,
            static fn (mixed $courseId): bool => is_string($courseId) && trim($courseId) !== '',
        ));
    }
}
