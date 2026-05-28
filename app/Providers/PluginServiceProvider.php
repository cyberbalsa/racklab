<?php

declare(strict_types=1);

namespace App\Providers;

use App\Plugins\PluginRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

final class PluginServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(PluginRegistry::class);
    }

    public function boot(PluginRegistry $plugins): void
    {
        $plugins->bootEnabledPlugins();
    }
}
