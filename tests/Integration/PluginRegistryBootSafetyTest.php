<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

it('skips enabled plugin lookup when the install database is unavailable', function (): void {
    $missingDatabase = sys_get_temp_dir().'/racklab-missing-plugin-'.bin2hex(random_bytes(6)).'.sqlite';
    $script = <<<'PHP'
        require 'vendor/autoload.php';

        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        if ($app->make(App\Plugins\PluginRegistry::class)->enabledPlugins() !== []) {
            fwrite(STDERR, 'Plugin registry should not boot plugins before the install database is ready.');
            exit(1);
        }

        fwrite(STDOUT, 'ok');
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $missingDatabase,
    ]);
    $process->run();

    if (! $process->isSuccessful()) {
        Assert::fail(trim($process->getErrorOutput().$process->getOutput()));
    }

    expect(trim($process->getOutput()))->toBe('ok');
});
