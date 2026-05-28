<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Throwable;

abstract class PluginLifecycleCommand extends Command
{
    /**
     * @param  Closure(): void  $action
     */
    protected function runLifecycleAction(Closure $action, string $successMessage): int
    {
        try {
            $action();
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->components->info($successMessage);

        return self::SUCCESS;
    }

    protected function slug(): string
    {
        $slug = $this->argument('slug');

        return is_string($slug) ? $slug : '';
    }
}
