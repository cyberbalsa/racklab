<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\DeploymentHostKey;
use App\Models\ProjectSshKey;

final readonly class ProvisioningPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function projectSshKey(ProjectSshKey $key): array
    {
        return [
            'id' => $key->getKey(),
            'tenant_id' => $key->tenant_id,
            'project_id' => $key->project_id,
            'name' => $key->name,
            'key_type' => $key->key_type,
            'public_key' => $key->public_key,
            'fingerprint' => $key->fingerprint,
            'metadata' => $key->metadata ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deploymentHostKey(DeploymentHostKey $key): array
    {
        return [
            'id' => $key->getKey(),
            'tenant_id' => $key->tenant_id,
            'deployment_id' => $key->deployment_id,
            'deployment_resource_id' => $key->deployment_resource_id,
            'key_type' => $key->key_type,
            'public_key' => $key->public_key,
            'fingerprint' => $key->fingerprint,
            'first_seen_at' => $key->first_seen_at->toJSON(),
        ];
    }
}
