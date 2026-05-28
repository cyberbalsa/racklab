<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Networking\ProviderDriftDetector;
use Illuminate\Console\Command;

final class DetectProviderDrift extends Command
{
    protected $signature = 'racklab:detect-provider-drift {--tenant= : Tenant id or slug to scan.} {--provider= : Provider slug to scan.}';

    protected $description = 'Detect RackLab-managed networking provider drift.';

    public function handle(ProviderDriftDetector $detector): int
    {
        $tenant = $this->option('tenant');
        $provider = $this->option('provider');

        $count = $detector->detect(
            tenant: is_string($tenant) ? $tenant : null,
            provider: is_string($provider) ? $provider : null,
        );

        $this->line(sprintf('Detected %d provider drift(s).', $count));

        return self::SUCCESS;
    }
}
