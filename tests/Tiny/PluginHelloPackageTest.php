<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Racklab\PluginHello\Manifest;
use Racklab\PluginHello\PluginHelloServiceProvider;

it('marks the package as a RackLab plugin without Laravel auto-discovery', function (): void {
    $composerJson = json_decode(
        (string) file_get_contents(__DIR__.'/../../packages/racklab/plugin-hello/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($composerJson['extra']['racklab']['plugin'])->toBeTrue()
        ->and($composerJson['extra']['laravel']['providers'] ?? [])->toBe([])
        ->and($composerJson['extra']['laravel']['aliases'] ?? [])->toBe([]);
});

it('declares the hello plugin package metadata', function (): void {
    $manifest = new Manifest;

    expect($manifest->slug())->toBe('racklab/plugin-hello')
        ->and($manifest->name())->toBe('RackLab Hello Plugin')
        ->and($manifest->description())->toContain('reference plugin');
});

it('provides a Laravel service provider without booting automatically', function (): void {
    expect(PluginHelloServiceProvider::class)->toExtend(ServiceProvider::class);
});
