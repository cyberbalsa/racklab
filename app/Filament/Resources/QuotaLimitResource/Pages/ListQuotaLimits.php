<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuotaLimitResource\Pages;

use App\Filament\Resources\QuotaLimitResource;
use Filament\Resources\Pages\ListRecords;

final class ListQuotaLimits extends ListRecords
{
    protected static string $resource = QuotaLimitResource::class;
}
