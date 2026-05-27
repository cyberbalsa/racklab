<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\AssertsNoAxeViolations;

uses(AssertsNoAxeViolations::class);

it('renders the hello page with the expected greeting', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/hello')
            ->waitForText('Hello, RackLab')
            ->assertSee('Hello, RackLab')
            ->assertSee('RackLab scaffold smoke test page.');
    });
});

it('renders the hello page with zero axe-core accessibility violations', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/hello')
            ->waitForText('Hello, RackLab');

        $this->assertNoAxeViolations($browser);
    });
});
