<?php

declare(strict_types=1);

it('renders the RackLab scaffold page', function (): void {
    $this->withoutVite();

    $this->get('/')
        ->assertOk()
        ->assertSee('RackLab scaffold');
});
