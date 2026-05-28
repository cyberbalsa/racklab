<?php

declare(strict_types=1);

it('declares the Reverb server and Echo client dependencies', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['laravel/reverb'] ?? null)->toBe('^1.10')
        ->and($package['devDependencies']['laravel-echo'] ?? null)->toBeString()
        ->and($package['devDependencies']['pusher-js'] ?? null)->toBeString();
});

it('wires Reverb broadcasting, channel auth, and frontend environment defaults', function (): void {
    $envExample = (string) file_get_contents(base_path('.env.example'));
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
    $frontend = (string) file_get_contents(resource_path('js/bootstrap.ts'));

    expect(config('broadcasting.default'))->toBeNull()
        ->and(config('broadcasting.connections.reverb.driver'))->toBe('reverb')
        ->and(config('reverb.default'))->toBe('reverb')
        ->and($envExample)->toContain('BROADCAST_CONNECTION=reverb')
        ->and($envExample)->toContain('VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"')
        ->and($bootstrap)->toContain('->withBroadcasting(')
        ->and($bootstrap)->toContain("'middleware' => ['web', 'auth', BindAuthenticatedTenant::class]")
        ->and($frontend)->toContain("broadcaster: 'reverb'")
        ->and($frontend)->toContain('VITE_REVERB_APP_KEY');
});
