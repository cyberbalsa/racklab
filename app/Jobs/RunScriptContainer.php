<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\Middleware\BindTenantContext;
use App\Runtime\ContainerManifest;
use App\Runtime\ScriptContainerRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

abstract class RunScriptContainer extends TenantAwareQueuedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 330;

    public function __construct(string $tenantId, public string $scriptRunId)
    {
        parent::__construct($tenantId);
        $this->onQueue('script-worker');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new BindTenantContext(app(TenantContextStore::class))];
    }

    abstract public static function containerManifest(): ContainerManifest;

    public function handle(ScriptContainerRunner $runner): void
    {
        $runner->run($this->scriptRunId, static::containerManifest());
    }
}
