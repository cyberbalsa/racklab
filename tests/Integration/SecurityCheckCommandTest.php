<?php

declare(strict_types=1);

it('passes the current RackLab security baseline', function (): void {
    $this->artisan('racklab:security-check')
        ->expectsOutputToContain('RackLab security checks passed.')
        ->assertSuccessful();
});

it('fails when public Scribe routes are enabled', function (): void {
    config(['scribe.laravel.add_routes' => true]);

    $this->artisan('racklab:security-check')
        ->expectsOutputToContain('Scribe Laravel routes must stay disabled')
        ->assertFailed();
});

it('fails production checks when debug mode is enabled', function (): void {
    config([
        'app.env' => 'production',
        'app.debug' => true,
        'session.encrypt' => true,
        'session.secure' => true,
    ]);

    $this->artisan('racklab:security-check')
        ->expectsOutputToContain('APP_DEBUG must be false in production')
        ->assertFailed();
});
