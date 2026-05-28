<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Runtime\PodmanStaleContainerReaper;
use Illuminate\Console\Command;

final class ReapScriptContainers extends Command
{
    protected $signature = 'racklab:reap-script-containers {--max-age=3600 : Maximum script container age in seconds before force cleanup.}';

    protected $description = 'Force-remove stale RackLab script containers left behind by timed-out jobs.';

    public function handle(PodmanStaleContainerReaper $reaper): int
    {
        $maxAge = $this->maxAge();

        if ($maxAge < 1) {
            $this->components->error('The --max-age option must be a positive integer.');

            return self::FAILURE;
        }

        $reaped = $reaper->reap($maxAge);

        $this->components->info(sprintf('Reaped %d stale RackLab script containers.', $reaped));

        return self::SUCCESS;
    }

    private function maxAge(): int
    {
        $value = $this->option('max-age');

        return is_numeric($value) ? (int) $value : 0;
    }
}
