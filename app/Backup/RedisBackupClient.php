<?php

declare(strict_types=1);

namespace App\Backup;

interface RedisBackupClient
{
    public function connect(RedisConnectionConfig $connection): RedisBackupConnection;
}
