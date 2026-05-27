<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('has complete RackLab translations for supported locales', function (): void {
    $this->artisan('translations:missing', [
        '--base' => 'en',
        '--only' => 'es',
    ])->assertSuccessful();
});

it('fails when a supported locale is missing a base translation key', function (): void {
    $langDirectory = storage_path('framework/testing/lang-'.Str::uuid());

    File::ensureDirectoryExists($langDirectory.'/en');
    File::ensureDirectoryExists($langDirectory.'/es');

    File::put($langDirectory.'/en/racklab.php', <<<'PHP'
        <?php

        return [
            'hello' => [
                'greeting' => 'Hello, RackLab',
                'description' => 'RackLab scaffold smoke test page.',
            ],
        ];
        PHP);

    File::put($langDirectory.'/es/racklab.php', <<<'PHP'
        <?php

        return [
            'hello' => [
                'greeting' => 'Hola, RackLab',
            ],
        ];
        PHP);

    try {
        $this->artisan('translations:missing', [
            '--dir' => $langDirectory,
            '--base' => 'en',
            '--only' => 'es',
        ])->assertExitCode(1);
    } finally {
        File::deleteDirectory($langDirectory);
    }
});
