<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\SecurityGroup;
use App\Models\SecurityGroupRule;

final readonly class SecurityGroupPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(SecurityGroup $securityGroup): array
    {
        $securityGroup->loadMissing('rules');

        return [
            'id' => $securityGroup->id,
            'tenant_id' => $securityGroup->tenant_id,
            'project_id' => $securityGroup->project_id,
            'name' => $securityGroup->name,
            'slug' => $securityGroup->slug,
            'state' => $securityGroup->state,
            'provider' => $securityGroup->provider,
            'provider_security_group_id' => $securityGroup->provider_security_group_id,
            'metadata' => $securityGroup->metadata ?? [],
            'rules' => $securityGroup->rules
                ->sortBy('position')
                ->values()
                ->map(static fn (SecurityGroupRule $rule): array => [
                    'id' => $rule->id,
                    'position' => $rule->position,
                    'direction' => $rule->direction,
                    'protocol' => $rule->protocol,
                    'ethertype' => $rule->ethertype,
                    'port_min' => $rule->port_min,
                    'port_max' => $rule->port_max,
                    'remote_cidr' => $rule->remote_cidr,
                    'state' => $rule->state,
                    'provider_rule_id' => $rule->provider_rule_id,
                    'provider_binding' => $rule->provider_binding ?? [],
                ])
                ->all(),
        ];
    }
}
