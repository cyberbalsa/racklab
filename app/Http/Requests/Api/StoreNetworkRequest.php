<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreNetworkRequest extends FormRequest
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
            'network_offering_id' => ['nullable', 'string', 'required_without:network_offering_slug'],
            'network_offering_slug' => ['nullable', 'string', 'required_without:network_offering_id'],
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'subnet' => ['required', 'array'],
            'subnet.cidr' => ['nullable', 'string', 'required_without_all:subnet.subnet_pool_id,subnet.subnet_pool_slug', $cidr],
            'subnet.subnet_pool_id' => ['nullable', 'string', 'required_without_all:subnet.cidr,subnet.subnet_pool_slug'],
            'subnet.subnet_pool_slug' => ['nullable', 'string', 'required_without_all:subnet.cidr,subnet.subnet_pool_id'],
            'subnet.prefix_length' => ['nullable', 'integer', 'between:1,32'],
            'subnet.gateway_ip' => ['nullable', 'ip'],
            'subnet.dhcp_enabled' => ['nullable', 'boolean'],
            'subnet.allocation_pools' => ['nullable', 'array'],
            'subnet.allocation_pools.*.start' => ['required_with:subnet.allocation_pools', 'ip'],
            'subnet.allocation_pools.*.end' => ['required_with:subnet.allocation_pools', 'ip'],
            'subnet.dns_nameservers' => ['nullable', 'array'],
            'subnet.dns_nameservers.*' => ['ip'],
            'subnet.host_routes' => ['nullable', 'array'],
            'subnet.host_routes.*.destination' => ['required_with:subnet.host_routes', $cidr],
            'subnet.host_routes.*.nexthop' => ['required_with:subnet.host_routes', 'ip'],
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
                'description' => 'Project that owns the self-service network.',
                'example' => '01HZPROJECT000000000000000',
            ],
            'network_offering_id' => [
                'description' => 'Approved network offering id. Use this or network_offering_slug.',
                'example' => '01HZNETOFFERING000000000',
            ],
            'network_offering_slug' => [
                'description' => 'Approved network offering slug. Use this or network_offering_id.',
                'example' => 'private-nat',
            ],
            'name' => [
                'description' => 'Human-readable project network name.',
                'example' => 'Student NAT network',
            ],
            'slug' => [
                'description' => 'Project-unique network slug.',
                'example' => 'student-nat',
            ],
            'subnet' => [
                'description' => 'Initial IPv4 subnet attached to the network. Provide cidr directly, or provide subnet_pool_id/subnet_pool_slug plus optional prefix_length for RackLab allocation.',
                'example' => ['subnet_pool_slug' => 'student-private-pool', 'prefix_length' => 24, 'gateway_ip' => '10.42.0.1'],
            ],
            'subnet.cidr' => [
                'description' => 'Explicit IPv4 CIDR for the initial subnet.',
                'example' => '10.42.0.0/24',
            ],
            'subnet.subnet_pool_id' => [
                'description' => 'Subnet pool id to allocate from when cidr is omitted.',
                'example' => '01HZSUBNETPOOL000000000',
            ],
            'subnet.subnet_pool_slug' => [
                'description' => 'Subnet pool slug to allocate from when cidr is omitted.',
                'example' => 'student-private-pool',
            ],
            'subnet.prefix_length' => [
                'description' => 'Requested prefix length for pool-backed allocation.',
                'example' => 24,
            ],
            'subnet.gateway_ip' => [
                'description' => 'Optional subnet gateway address.',
                'example' => '10.42.0.1',
            ],
            'subnet.dhcp_enabled' => [
                'description' => 'Whether DHCP should be enabled for the subnet.',
                'example' => true,
            ],
            'subnet.allocation_pools' => [
                'description' => 'Optional DHCP allocation pools.',
                'example' => [['start' => '10.42.0.20', 'end' => '10.42.0.200']],
            ],
            'subnet.allocation_pools.*.start' => [
                'description' => 'Allocation pool start address.',
                'example' => '10.42.0.20',
            ],
            'subnet.allocation_pools.*.end' => [
                'description' => 'Allocation pool end address.',
                'example' => '10.42.0.200',
            ],
            'subnet.dns_nameservers' => [
                'description' => 'Optional DNS resolvers for guests on this subnet.',
                'example' => ['1.1.1.1', '8.8.8.8'],
            ],
            'subnet.dns_nameservers.*' => [
                'description' => 'DNS resolver IP address.',
                'example' => '1.1.1.1',
            ],
            'subnet.host_routes' => [
                'description' => 'Optional static routes advertised to guests.',
                'example' => [['destination' => '10.50.0.0/16', 'nexthop' => '10.42.0.1']],
            ],
            'subnet.host_routes.*.destination' => [
                'description' => 'Static route destination CIDR.',
                'example' => '10.50.0.0/16',
            ],
            'subnet.host_routes.*.nexthop' => [
                'description' => 'Static route next-hop address.',
                'example' => '10.42.0.1',
            ],
            'metadata' => [
                'description' => 'Optional network metadata.',
                'example' => ['purpose' => 'lab'],
            ],
        ];
    }
}
