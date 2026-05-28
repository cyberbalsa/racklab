<?php

declare(strict_types=1);

namespace App\Backup;

final readonly class BackupArchive
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     */
    public function __construct(
        public array $manifest,
        public array $files,
    ) {}
}
