<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class SecurityCheck extends Command
{
    protected $signature = 'racklab:security-check';

    protected $description = 'Run RackLab Laravel security configuration checks.';

    public function handle(): int
    {
        $failures = $this->failures();

        if ($failures === []) {
            $this->info('RackLab security checks passed.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function failures(): array
    {
        $failures = [];

        if (config('scribe.laravel.add_routes') !== false) {
            $failures[] = 'Scribe Laravel routes must stay disabled; publish docs through committed artifacts instead.';
        }

        if (config('scribe.try_it_out.enabled') !== false) {
            $failures[] = 'Scribe Try It Out must stay disabled so generated docs never issue authenticated browser requests.';
        }

        if (config('permission.register_permission_check_method') !== false) {
            $failures[] = 'Spatie permission Gate registration must stay disabled; AccessResolver is RackLab authorization gatekeeper.';
        }

        if ($this->isProduction()) {
            if (config('app.debug') !== false) {
                $failures[] = 'APP_DEBUG must be false in production.';
            }

            if (config('session.encrypt') !== true) {
                $failures[] = 'SESSION_ENCRYPT must be true in production.';
            }

            if (config('session.secure') !== true) {
                $failures[] = 'SESSION_SECURE_COOKIE must be true in production.';
            }

            $appUrl = config('app.url');

            if (! is_string($appUrl) || ! str_starts_with($appUrl, 'https://')) {
                $failures[] = 'APP_URL must use https:// in production.';
            }
        }

        if (! $this->isLocalLike() && config('racklab.proxmox.verify_ssl') !== true) {
            $failures[] = 'RACKLAB_PROXMOX_VERIFY_SSL must stay true outside local/test environments.';
        }

        return $failures;
    }

    private function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    private function isLocalLike(): bool
    {
        return in_array(config('app.env'), ['local', 'testing'], true);
    }
}
