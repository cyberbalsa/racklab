<?php

declare(strict_types=1);

use App\Domain\Console\ConsoleKind;
use App\Livewire\Console\DeploymentConsolePane;

it('exposes the configured console kind through consoleKind()', function (): void {
    $component = new DeploymentConsolePane;
    $component->consoleKindValue = 'terminal';

    expect($component->consoleKind())->toBe(ConsoleKind::Terminal);
});

it('defaults to vnc when no kind is set', function (): void {
    $component = new DeploymentConsolePane;

    expect($component->consoleKind())->toBe(ConsoleKind::Vnc);
});

it('starts in the idle status', function (): void {
    $component = new DeploymentConsolePane;

    expect($component->statusKey)->toBe('racklab.console.idle');
});
