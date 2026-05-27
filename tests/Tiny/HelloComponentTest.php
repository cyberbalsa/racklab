<?php

declare(strict_types=1);

use App\Livewire\Hello;

it('renders a greeting with the configured site name', function (): void {
    $component = new Hello;
    $component->mount();

    expect($component->greeting)->toBe('Hello, RackLab');
});

it('formats greeting with a custom subject', function (): void {
    $component = new Hello;
    $component->mount('Forrest');

    expect($component->greeting)->toBe('Hello, Forrest');
});
