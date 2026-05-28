<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Resources\CourseResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCourse extends CreateRecord
{
    protected static string $resource = CourseResource::class;
}
