<?php

declare(strict_types=1);

namespace App\Backup;

interface RedisBackupConnection
{
    /**
     * @param  list<string>  $arguments
     */
    public function command(array $arguments): mixed;

    public function close(): void;
}
