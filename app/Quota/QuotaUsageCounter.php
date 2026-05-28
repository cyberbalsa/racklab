<?php

declare(strict_types=1);

namespace App\Quota;

use App\Models\QuotaLimit;
use App\Models\QuotaReservation;
use App\Models\QuotaUsage;

final readonly class QuotaUsageCounter
{
    public function usedForLimit(QuotaLimit $limit): int
    {
        return (int) QuotaUsage::query()
            ->where('tenant_id', $limit->tenant_id)
            ->where('scope_type', $limit->scope_type)
            ->where('scope_id', $limit->scope_id)
            ->where('dimension', $limit->dimension)
            ->where('state', 'active')
            ->sum('quantity') + (int) QuotaReservation::query()
            ->where('tenant_id', $limit->tenant_id)
            ->where('scope_type', $limit->scope_type)
            ->where('scope_id', $limit->scope_id)
            ->where('dimension', $limit->dimension)
            ->where('state', 'reserved')
            ->sum('quantity');
    }
}
