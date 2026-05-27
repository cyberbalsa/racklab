<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Hello extends Component
{
    public string $greeting = '';

    public function mount(string $subject = 'RackLab'): void
    {
        $this->greeting = sprintf('Hello, %s', $subject);
    }

    public function render(): View
    {
        return view('livewire.hello');
    }
}
