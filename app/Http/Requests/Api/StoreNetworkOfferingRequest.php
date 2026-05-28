<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreNetworkOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'offering_type' => ['required', 'string', Rule::in(['private-isolated', 'private-nat', 'double-nat', 'provider-direct', 'template-defined'])],
            'reachability' => ['required', 'string', Rule::in(['routable_from_management', 'nat_from_management', 'isolated_no_ingress'])],
            'provider_network' => ['required', 'array'],
            'provider_network.name' => ['required', 'string', 'max:160'],
            'provider_network.provider' => ['required', 'string', 'max:80'],
            'provider_network.provider_cluster' => ['nullable', 'string', 'max:120'],
            'provider_network.external_id' => ['required', 'string', 'max:160'],
            'provider_network.network_type' => ['required', 'string', Rule::in(['bridge', 'vlan', 'vnet', 'sdn_zone'])],
            'provider_network.bridge' => ['nullable', 'string', 'max:120'],
            'provider_network.vlan_tag' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'provider_network.metadata' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Human-readable network offering name.',
                'example' => 'Private isolated',
            ],
            'slug' => [
                'description' => 'Tenant-unique network offering slug used by Stack definitions.',
                'example' => 'private-isolated',
            ],
            'offering_type' => [
                'description' => 'Productized network offering type.',
                'example' => 'private-isolated',
            ],
            'reachability' => [
                'description' => 'Management-plane reachability capability consumed by SSH/console surfaces.',
                'example' => 'isolated_no_ingress',
            ],
            'provider_network' => [
                'description' => 'Backend provider network mapping selected by the administrator.',
                'example' => ['provider' => 'proxmox', 'network_type' => 'bridge', 'external_id' => 'vmbr100'],
            ],
            'metadata' => [
                'description' => 'Offering metadata such as static NAT gateway host/port information.',
                'example' => ['nat' => ['host' => '198.51.100.10', 'port' => 2201]],
            ],
        ];
    }
}
