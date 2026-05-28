<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\Middleware\BindTenantContext;
use App\Providers\Proxmox\Exceptions\ProviderTaskWaitTimeout;
use App\Providers\Proxmox\TaskPoller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PollProxmoxTask extends TenantAwareQueuedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(string $tenantId, private readonly string $providerTaskId)
    {
        parent::__construct($tenantId);
        $this->onQueue('provider-worker');
    }

    public function providerTaskId(): string
    {
        return $this->providerTaskId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new BindTenantContext(app(TenantContextStore::class))];
    }

    public function handle(TaskPoller $poller): void
    {
        try {
            $poller->pollUntilTerminal($this->providerTaskId);
        } catch (ProviderTaskWaitTimeout) {
            // The reconciler resumes by UPID; the original operation must not be re-submitted.
        }
    }
}
