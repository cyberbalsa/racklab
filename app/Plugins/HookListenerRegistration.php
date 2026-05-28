<?php

declare(strict_types=1);

namespace App\Plugins;

use Closure;

final readonly class HookListenerRegistration
{
    public function __construct(
        public string $eventClass,
        public Closure $listener,
        public HookListenerStyle $style,
        public string $pluginSlug,
        public int $priority,
    ) {}
}
