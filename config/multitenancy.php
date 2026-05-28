<?php

declare(strict_types=1);

use App\Models\Tenant;

return [
    'tenant_model' => Tenant::class,
    'queues_are_tenant_aware_by_default' => false,
];
