<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\ProviderDrift;

final readonly class ProviderDriftPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(ProviderDrift $drift): array
    {
        return [
            'id' => $drift->getKey(),
            'tenant_id' => $drift->tenant_id,
            'project_id' => $drift->project_id,
            'provider' => $drift->provider,
            'resource_type' => $drift->resource_type,
            'resource_id' => $drift->resource_id,
            'resource_label' => $drift->resource_label,
            'state' => $drift->state,
            'expected_state' => $drift->expected_state,
            'observed_state' => $drift->observed_state,
            'drift' => $drift->drift,
            'detected_at' => $drift->detected_at->toJSON(),
            'resolved_at' => $drift->resolved_at?->toJSON(),
            'resolved_by_id' => $drift->resolved_by_id,
            'resolution' => $drift->resolution,
            'metadata' => $drift->metadata ?? [],
        ];
    }
}
