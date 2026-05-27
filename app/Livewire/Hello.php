<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Hello extends Component
{
    public string $subject = '';

    public function mount(string $subject = 'RackLab'): void
    {
        $this->subject = $subject;
    }

    public function render(): View
    {
        return view('livewire.hello');
    }
}
