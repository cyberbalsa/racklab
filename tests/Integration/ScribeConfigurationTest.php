<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

it('loads the Scribe config without the dev-only Scribe package', function (): void {
    $script = <<<'PHP'
        function env(string $key, mixed $default = null): mixed
        {
            return $default;
        }

        function config(string|null $key = null, mixed $default = null): mixed
        {
            return match ($key) {
                'app.url' => 'http://racklab.test',
                'database.default' => 'sqlite',
                default => $default,
            };
        }

        $config = require 'config/scribe.php';

        if (($config['auth']['in'] ?? null) !== 'header') {
            fwrite(STDERR, 'Scribe auth location must remain header.');
            exit(1);
        }

        $responseCallStrategy = 'Knuckles\\Scribe\\Extracting\\Strategies\\Responses\\ResponseCalls';

        foreach ($config['strategies']['responses'] ?? [] as $strategy) {
            $strategyName = is_string($strategy) ? $strategy : ($strategy[0] ?? null);

            if ($strategyName === $responseCallStrategy) {
                fwrite(STDERR, 'Scribe response calls must stay disabled.');
                exit(1);
            }
        }

        fwrite(STDOUT, 'ok');
        PHP;

    $process = new Process([PHP_BINARY, '-r', $script], base_path());
    $process->run();

    if (! $process->isSuccessful()) {
        Assert::fail(trim($process->getErrorOutput().$process->getOutput()));
    }

    expect(trim($process->getOutput()))->toBe('ok');
});
