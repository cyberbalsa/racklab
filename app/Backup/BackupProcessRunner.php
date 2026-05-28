<?php

declare(strict_types=1);

namespace App\Backup;

interface BackupProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(array $command, array $environment = [], ?string $input = null): BackupProcessResult;
}
