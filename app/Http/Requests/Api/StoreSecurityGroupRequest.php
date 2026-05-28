<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSecurityGroupRequest extends FormRequest
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
            'project_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
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
            'project_id' => [
                'description' => 'Project that owns the security group.',
                'example' => '01HZPROJECT000000000000000',
            ],
            'name' => [
                'description' => 'Human-readable security group name.',
                'example' => 'Web security group',
            ],
            'slug' => [
                'description' => 'Project-unique security group slug.',
                'example' => 'web-sg',
            ],
            'rules' => [
                'description' => 'Initial firewall rules to realize for this security group.',
                'example' => [
                    ['direction' => 'ingress', 'protocol' => 'tcp', 'port_min' => 443, 'port_max' => 443, 'remote_cidr' => '0.0.0.0/0'],
                ],
            ],
            'rules.*.direction' => [
                'description' => 'Rule traffic direction.',
                'example' => 'ingress',
            ],
            'rules.*.protocol' => [
                'description' => 'Rule protocol.',
                'example' => 'tcp',
            ],
            'rules.*.port_min' => [
                'description' => 'Minimum destination port for TCP/UDP rules.',
                'example' => 443,
            ],
            'rules.*.port_max' => [
                'description' => 'Maximum destination port for TCP/UDP rules.',
                'example' => 443,
            ],
            'rules.*.remote_cidr' => [
                'description' => 'Remote IPv4 CIDR allowed by this rule.',
                'example' => '0.0.0.0/0',
            ],
        ];
    }
}
