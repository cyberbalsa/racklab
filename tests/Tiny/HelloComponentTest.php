<?php

declare(strict_types=1);

use App\Livewire\Hello;

it('stores the default greeting subject', function (): void {
    $component = new Hello;
    $component->mount();

    expect($component->subject)->toBe('RackLab');
});

it('stores a custom greeting subject', function (): void {
    $component = new Hello;
    $component->mount('Forrest');

    expect($component->subject)->toBe('Forrest');
});
