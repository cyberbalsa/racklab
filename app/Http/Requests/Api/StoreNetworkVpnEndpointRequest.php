<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreNetworkVpnEndpointRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'project_id' => ['required', 'string'],
            'network_id' => ['required', 'string'],
            'vpn_public_ip_pool_id' => ['required_without:vpn_public_ip_pool_slug', 'string'],
            'vpn_public_ip_pool_slug' => ['required_without:vpn_public_ip_pool_id', 'string'],
            'deployment_id' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Human-readable VPN endpoint name.',
                'example' => 'lab-vpn',
            ],
            'project_id' => [
                'description' => 'Project that owns the VPN endpoint.',
                'example' => '01HZPROJECT0000000000000000',
            ],
            'network_id' => [
                'description' => 'Tenant network the VPN endpoint attaches to.',
                'example' => '01HZNETWORK000000000000000',
            ],
            'vpn_public_ip_pool_id' => [
                'description' => 'Admin-published VPN public IP pool id. Provide id OR slug.',
                'example' => '01HZVPNPOOL000000000000000',
            ],
            'vpn_public_ip_pool_slug' => [
                'description' => 'Admin-published VPN public IP pool slug. Provide id OR slug.',
                'example' => 'default-vpn-public',
            ],
            'deployment_id' => [
                'description' => 'Optional Deployment this endpoint is bound to for lifecycle propagation.',
                'example' => '01HZDEPLOYMENT000000000000',
            ],
        ];
    }
}
