<?php

declare(strict_types=1);

namespace Racklab\PluginHello;

use Illuminate\Support\ServiceProvider;
use Override;

final class PluginHelloServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
