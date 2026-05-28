<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuotaLimitResource\Pages;

use App\Filament\Resources\QuotaLimitResource;
use Filament\Resources\Pages\EditRecord;

final class EditQuotaLimit extends EditRecord
{
    protected static string $resource = QuotaLimitResource::class;
}
