<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFloatingIpRequest extends FormRequest
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
            'project_id' => ['required', 'string'],
            'floating_ip_pool_id' => ['nullable', 'string', 'required_without:floating_ip_pool_slug'],
            'floating_ip_pool_slug' => ['nullable', 'string', 'required_without:floating_ip_pool_id'],
            'deployment_network_binding_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'project_id' => [
                'description' => 'Project that owns the floating IP allocation.',
                'example' => '01HZPROJECT000000000000000',
            ],
            'floating_ip_pool_id' => [
                'description' => 'Admin-published floating IP pool id. Use this or floating_ip_pool_slug.',
                'example' => '01HZFIPPOOL000000000000',
            ],
            'floating_ip_pool_slug' => [
                'description' => 'Admin-published floating IP pool slug. Use this or floating_ip_pool_id.',
                'example' => 'public-test-pool',
            ],
            'deployment_network_binding_id' => [
                'description' => 'Optional deployment NIC binding to map the floating IP to.',
                'example' => '01HZNETBINDING000000000',
            ],
            'metadata' => [
                'description' => 'Optional floating IP metadata.',
                'example' => ['purpose' => 'ssh'],
            ],
        ];
    }
}
