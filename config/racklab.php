<?php

declare(strict_types=1);

return [
    'default_tenant_slug' => env('RACKLAB_DEFAULT_TENANT_SLUG', 'default'),
    'seed_demo_catalog' => env('RACKLAB_SEED_DEMO_CATALOG', true),
    'container_runtime' => env('RACKLAB_CONTAINER_RUNTIME', 'unavailable'),
    'podman' => [
        'binary' => env('RACKLAB_PODMAN_BINARY', 'podman'),
    ],
    'health' => [
        'redis_required' => (bool) env('RACKLAB_HEALTH_REDIS_REQUIRED', false),
    ],
    'proxmox' => [
        'enabled' => (bool) env('RACKLAB_PROXMOX_ENABLED', false),
        'base_uri' => env('RACKLAB_PROXMOX_BASE_URI'),
        'api_token_id' => env('RACKLAB_PROXMOX_API_TOKEN_ID'),
        'api_token_secret' => env('RACKLAB_PROXMOX_API_TOKEN_SECRET'),
        'verify_ssl' => env('RACKLAB_PROXMOX_VERIFY_SSL', true),
        'ca_bundle' => env('RACKLAB_PROXMOX_CA_BUNDLE'),
        'connect_timeout' => (float) env('RACKLAB_PROXMOX_CONNECT_TIMEOUT', 5),
        'read_timeout' => (float) env('RACKLAB_PROXMOX_READ_TIMEOUT', 30),
    ],
    'supported_locales' => ['en', 'es'],
    'jwt' => [
        'issuer' => env('RACKLAB_JWT_ISSUER', env('APP_URL', 'http://localhost')),
        'audience' => env('RACKLAB_JWT_AUDIENCE', 'racklab'),
        'ttl_seconds' => (int) env('RACKLAB_JWT_TTL_SECONDS', 300),
    ],
    'console' => [
        'grant_ttl_seconds' => (int) env('RACKLAB_CONSOLE_GRANT_TTL_SECONDS', 300),
        'proxy' => env('RACKLAB_CONSOLE_PROXY', 'unavailable'),
    ],
];
