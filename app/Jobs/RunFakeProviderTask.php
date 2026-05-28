<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Deployments\FakeProviderTaskRunner;
use App\Domain\Tenancy\TenantContextStore;
use App\Jobs\Middleware\BindTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunFakeProviderTask extends TenantAwareQueuedJob implements ShouldQueue
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

    public function handle(FakeProviderTaskRunner $runner): void
    {
        $runner->run($this->providerTaskId);
    }
}
