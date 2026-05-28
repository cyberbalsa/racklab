<?php

declare(strict_types=1);

namespace App\Runtime;

interface ContainerProcessRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(array $command, int $timeoutSeconds): ContainerRunResult;
}
