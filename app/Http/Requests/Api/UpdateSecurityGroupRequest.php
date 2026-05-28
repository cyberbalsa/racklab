<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSecurityGroupRequest extends FormRequest
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
        $cidr = 'regex:/^([0-9]{1,3}\.){3}[0-9]{1,3}\/([0-9]|[12][0-9]|3[0-2])$/';

        return [
            'name' => ['nullable', 'string', 'max:160'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.direction' => ['required', 'string', 'in:ingress,egress'],
            'rules.*.protocol' => ['required', 'string', 'in:tcp,udp,icmp,any'],
            'rules.*.port_min' => ['nullable', 'integer', 'between:1,65535'],
            'rules.*.port_max' => ['nullable', 'integer', 'between:1,65535'],
            'rules.*.remote_cidr' => ['nullable', 'string', $cidr],
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
                'description' => 'Optional updated security group name.',
                'example' => 'Web security group updated',
            ],
            'rules' => [
                'description' => 'Replacement firewall rule list for this security group.',
                'example' => [
                    ['direction' => 'egress', 'protocol' => 'any', 'remote_cidr' => '0.0.0.0/0'],
                ],
            ],
        ];
    }
}
