<?php

declare(strict_types=1);

namespace App\OpenApi;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Override;
use stdClass;

final class RackLabResponseDefaultsGenerator extends OpenApiGenerator
{
    /**
     * @param  array<string, mixed>  $pathItem
     * @param  array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}>  $groupedEndpoints
     * @return array<string, mixed>
     */
    #[Override]
    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $method = strtolower((string) $endpoint->httpMethods[0]);
        $uri = '/'.ltrim($endpoint->uri, '/');
        $metadata = $this->operationMetadata($method, $uri);

        if (($pathItem['summary'] ?? '') === '') {
            $pathItem['summary'] = $metadata['summary'];
        }

        if (($pathItem['description'] ?? '') === '') {
            $pathItem['description'] = $metadata['description'];
        }

        if ($this->responsesAreEmpty($pathItem['responses'] ?? [])) {
            $pathItem['responses'] = $this->defaultResponses($method, $uri);
        }

        return $pathItem;
    }

    /**
     * @return array{summary: string, description: string}
     */
    private function operationMetadata(string $method, string $uri): array
    {
        $operations = [
            'post /api/v1/provisioning/host-keys/{}' => [
                'Record deployment host keys',
                'Records guest-reported host public keys for a deployment-scoped phone-home token.',
            ],
            'get /api/v1/me' => [
                'Show current API user',
                'Returns the authenticated user and active tenant context.',
            ],
            'get /api/v1/artifacts/{}' => [
                'Download artifact',
                'Streams authorized artifact bytes without exposing internal storage paths.',
            ],
            'get /api/v1/catalog/items' => [
                'List catalog items',
                'Lists catalog items visible to the authenticated actor.',
            ],
            'get /api/v1/catalog/items/{}/versions/{}' => [
                'Show catalog version',
                'Returns a published catalog version and its Stack definition payload.',
            ],
            'get /api/v1/courses' => [
                'List courses',
                'Lists courses readable through RackLab tenant RBAC.',
            ],
            'post /api/v1/network-offerings' => [
                'Create network offering',
                'Publishes an admin-managed provider network offering and its management-plane reachability capability.',
            ],
            'post /api/v1/networks' => [
                'Create project network',
                'Creates a self-service project network and initial subnet from an approved network offering.',
            ],
            'post /api/v1/routers' => [
                'Create project router',
                'Creates a managed router that connects two or more project networks and consumes router quota.',
            ],
            'post /api/v1/floating-ips' => [
                'Allocate floating IP',
                'Allocates a floating IP from an admin-published pool and optionally maps it to a deployment NIC binding.',
            ],
            'delete /api/v1/floating-ips/{}' => [
                'Release floating IP',
                'Releases an allocated floating IP, returns quota capacity, and makes the address available for reuse.',
            ],
            'post /api/v1/security-groups' => [
                'Create security group',
                'Creates a project security group and realizes its firewall rules through the provider.',
            ],
            'patch /api/v1/security-groups/{}' => [
                'Update security group',
                'Replaces a security group firewall rule list and refreshes provider realization metadata.',
            ],
            'post /api/v1/provider-drifts/{}/repair' => [
                'Repair provider drift',
                'Reasserts RackLab intent for a detected provider drift record and marks the drift repaired.',
            ],
            'post /api/v1/provider-drifts/{}/adopt' => [
                'Adopt provider drift',
                'Marks the observed provider-side state as authoritative and updates RackLab state from it.',
            ],
            'get /api/v1/projects' => [
                'List projects',
                'Lists Projects readable through RackLab tenant RBAC.',
            ],
            'get /api/v1/projects/{}/ssh-keys' => [
                'List Project SSH keys',
                'Lists Project SSH keys available for cloud-init injection.',
            ],
            'post /api/v1/projects/{}/ssh-keys' => [
                'Create Project SSH key',
                'Stores a Project SSH public key and computes its server-owned fingerprint.',
            ],
            'get /api/v1/projects/{}/stacks' => [
                'List Project Stacks',
                'Lists Project-local Stack definitions available for deployment.',
            ],
            'post /api/v1/projects/{}/stacks' => [
                'Create Project Stack',
                'Creates a Project-local Stack definition for later deployment.',
            ],
            'get /api/v1/deployments' => [
                'List deployments',
                'Lists deployments visible to the authenticated actor.',
            ],
            'post /api/v1/deployments' => [
                'Create deployment',
                'Creates or replays an idempotent deployment request from a Project Stack or catalog version.',
            ],
            'post /api/v1/deployments/{}/cloud-init' => [
                'Render deployment cloud-init',
                'Renders redacted cloud-init metadata and a one-time host-key phone-home token for a deployment.',
            ],
            'post /api/v1/deployments/{}/console-grant' => [
                'Issue console access grant',
                'Issues a short-lived Track A JWT scoped to a single deployment + console kind for opening a noVNC/xterm console pane.',
            ],
            'post /api/v1/deployments/{}/operations' => [
                'Create deployment operation',
                'Creates or replays an idempotent deployment lifecycle operation.',
            ],
            'get /api/v1/deployments/{}' => [
                'Show deployment',
                'Returns a deployment, its resources, and the latest operation context.',
            ],
            'get /api/v1/replay' => [
                'Replay channel events',
                'Returns replay-log events after a cursor or a gap sentinel when replay history is unavailable.',
            ],
            'post /api/v1/scripts' => [
                'Create script',
                'Creates a Project-scoped script and immutable executable version.',
            ],
            'patch /api/v1/scripts/{}' => [
                'Update script',
                'Updates script metadata or creates a new executable version when command/source changes.',
            ],
            'post /api/v1/scripts/{}/approvals' => [
                'Approve script version',
                'Creates a scoped approval for the current executable script version.',
            ],
            'post /api/v1/scripts/{}/runs' => [
                'Run script',
                'Queues an approved script run on the selected runner substrate.',
            ],
            'get /api/v1/scripts/{}/runs/{}' => [
                'Show script run',
                'Returns a script run ledger row and authorized artifact metadata.',
            ],
            'get /api/v1/tokens' => [
                'List Track-B tokens',
                'Lists retained Track-B token grants for the authenticated owner.',
            ],
            'post /api/v1/tokens' => [
                'Create Track-B token',
                'Issues a scoped opaque personal access token and returns the raw token once.',
            ],
            'delete /api/v1/tokens/{}' => [
                'Revoke Track-B token',
                'Revokes an owned Track-B token grant and deletes the Sanctum token hash.',
            ],
        ];

        $operation = $operations[$method.' '.$this->comparableUri($uri)] ?? null;

        if (is_array($operation)) {
            return [
                'summary' => $operation[0],
                'description' => $operation[1],
            ];
        }

        return [
            'summary' => $this->fallbackSummary($method, $uri),
            'description' => 'Executes the RackLab API operation with tenant, token, and RBAC enforcement.',
        ];
    }

    private function comparableUri(string $uri): string
    {
        return (string) preg_replace('/\{[^}]+}/', '{}', $uri);
    }

    private function fallbackSummary(string $method, string $uri): string
    {
        $parts = array_values(array_filter(explode('/', $this->comparableUri($uri)), static fn (string $part): bool => ! in_array($part, ['', 'api', 'v1', '{}'], true)));
        $label = $parts === [] ? 'resource' : str_replace('-', ' ', end($parts));

        return match ($method) {
            'get' => 'Show '.$label,
            'post' => 'Create '.$label,
            'patch' => 'Update '.$label,
            'delete' => 'Delete '.$label,
            default => ucfirst($method).' '.$label,
        };
    }

    private function responsesAreEmpty(mixed $responses): bool
    {
        if ($responses instanceof stdClass) {
            return (array) $responses === [];
        }

        return $responses === [];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function defaultResponses(string $method, string $uri): array
    {
        if ($method === 'delete') {
            return [
                '204' => [
                    'description' => 'No content.',
                ],
            ];
        }

        if ($method === 'get' && str_contains($uri, '/artifacts/')) {
            return [
                '200' => [
                    'description' => 'Artifact bytes.',
                    'content' => [
                        'application/octet-stream' => [
                            'schema' => [
                                'type' => 'string',
                                'format' => 'binary',
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($method === 'get' && $uri === '/api/v1/replay') {
            return [
                '200' => [
                    'description' => 'Replay events or a replay-gap sentinel.',
                    'content' => [
                        'application/json' => [
                            'example' => [
                                'gap' => false,
                                'events' => [
                                    [
                                        'id' => 42,
                                        'channel' => 'tenant.default.deployments',
                                        'event' => 'deployment.updated',
                                        'payload' => ['deployment_id' => '01HZDEPLOYMENT000000000000'],
                                    ],
                                ],
                            ],
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'gap' => ['type' => 'boolean'],
                                    'events' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'object'],
                                    ],
                                ],
                                'required' => ['gap', 'events'],
                                'examples' => [
                                    [
                                        'gap' => false,
                                        'events' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($method === 'post') {
            return [
                '200' => $this->jsonDataEnvelope(
                    'Existing resource returned for an idempotent replay.',
                    false,
                    $this->responseExample($method, $uri),
                ),
                '201' => $this->jsonDataEnvelope(
                    'Resource created.',
                    false,
                    $this->responseExample($method, $uri),
                ),
            ];
        }

        $isListEndpoint = $this->isListEndpoint($method, $uri);

        return [
            '200' => $this->jsonDataEnvelope(
                $isListEndpoint ? 'Resource collection.' : 'Resource representation.',
                $isListEndpoint,
                $this->responseExample($method, $uri, $isListEndpoint),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $example
     * @return array<string, mixed>
     */
    private function jsonDataEnvelope(string $description, bool $collection = false, ?array $example = null): array
    {
        $dataExample = $collection
            ? [
                [
                    'id' => '01HZEXAMPLE0000000000000000',
                ],
            ]
            : [
                'id' => '01HZEXAMPLE0000000000000000',
            ];
        $example ??= ['data' => $dataExample];
        $dataSchema = $this->schemaForValue($example['data'] ?? $dataExample);

        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'example' => $example,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => $dataSchema,
                        ],
                        'required' => ['data'],
                        'examples' => [
                            $example,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function isListEndpoint(string $method, string $uri): bool
    {
        if ($method !== 'get') {
            return false;
        }

        return in_array($uri, [
            '/api/v1/catalog/items',
            '/api/v1/courses',
            '/api/v1/deployments',
            '/api/v1/projects',
            '/api/v1/tokens',
        ], true) || str_ends_with($uri, '/ssh-keys') || str_ends_with($uri, '/stacks');
    }

    /**
     * @return array<string, mixed>
     */
    private function responseExample(string $method, string $uri, bool $collection = false): array
    {
        $data = match ($method.' '.$this->comparableUri($uri)) {
            'post /api/v1/provisioning/host-keys/{}' => $this->hostKeyPhoneHomeExample(),
            'get /api/v1/me' => [
                'id' => 17,
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.edu',
                'tenant' => [
                    'id' => '01HZTENANT0000000000000000',
                    'name' => 'Default Tenant',
                    'slug' => 'default',
                ],
                'profile' => [
                    'display_name' => 'Ada Lovelace',
                    'locale' => 'en',
                ],
            ],
            'get /api/v1/catalog/items' => $this->catalogItemExample(),
            'get /api/v1/catalog/items/{}/versions/{}' => $this->catalogVersionExample(),
            'get /api/v1/courses' => $this->courseExample(),
            'post /api/v1/network-offerings' => $this->networkOfferingExample(),
            'post /api/v1/networks' => $this->networkExample(),
            'post /api/v1/routers' => $this->routerExample(),
            'post /api/v1/floating-ips' => $this->floatingIpExample(),
            'post /api/v1/security-groups',
            'patch /api/v1/security-groups/{}' => $this->securityGroupExample(),
            'post /api/v1/provider-drifts/{}/repair',
            'post /api/v1/provider-drifts/{}/adopt' => $this->providerDriftExample(),
            'get /api/v1/projects' => $this->projectExample(),
            'get /api/v1/projects/{}/ssh-keys',
            'post /api/v1/projects/{}/ssh-keys' => $this->projectSshKeyExample(),
            'get /api/v1/projects/{}/stacks',
            'post /api/v1/projects/{}/stacks' => $this->stackDefinitionExample(),
            'get /api/v1/deployments',
            'post /api/v1/deployments',
            'get /api/v1/deployments/{}',
            'post /api/v1/deployments/{}/operations' => $this->deploymentExample(),
            'post /api/v1/deployments/{}/cloud-init' => $this->cloudInitExample(),
            'post /api/v1/deployments/{}/console-grant' => $this->consoleGrantExample(),
            'get /api/v1/tokens' => $this->tokenGrantExample(false),
            'post /api/v1/tokens' => $this->tokenGrantExample(true),
            'post /api/v1/scripts',
            'patch /api/v1/scripts/{}' => $this->scriptExample(),
            'post /api/v1/scripts/{}/approvals' => $this->scriptApprovalExample(),
            'post /api/v1/scripts/{}/runs',
            'get /api/v1/scripts/{}/runs/{}' => $this->scriptRunExample(),
            default => [
                'id' => '01HZEXAMPLE0000000000000000',
            ],
        };

        return [
            'data' => $collection ? [$data] : $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hostKeyPhoneHomeExample(): array
    {
        return [
            'deployment_id' => '01HZDEPLOYMENT000000000000',
            'keys_recorded' => 1,
            'keys' => [
                [
                    'id' => '01HZHOSTKEY00000000000000',
                    'tenant_id' => '01HZTENANT0000000000000000',
                    'deployment_id' => '01HZDEPLOYMENT000000000000',
                    'deployment_resource_id' => '01HZRESOURCE00000000000000',
                    'key_type' => 'ssh-ed25519',
                    'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIRackLabHostKey guest@example',
                    'fingerprint' => 'SHA256:exampleHostKeyFingerprint',
                    'first_seen_at' => '2026-05-27T19:00:00.000000Z',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogItemExample(): array
    {
        return [
            'id' => '01HZCATALOG00000000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'name' => 'Kali Workstation',
            'slug' => 'kali-workstation',
            'description' => 'Single-VM workstation template for introductory labs.',
            'sharing_scope' => 'tenant_local',
            'current_version' => [
                'id' => '01HZCATVERSION00000000000',
                'catalog_item_id' => '01HZCATALOG00000000000000',
                'stack_definition_id' => '01HZSTACK0000000000000000',
                'version' => '2026.05',
                'state' => 'published',
                'summary' => 'Initial RackLab MVP template.',
                'published_at' => '2026-05-27T19:00:00.000000Z',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogVersionExample(): array
    {
        return [
            'id' => '01HZCATVERSION00000000000',
            'catalog_item_id' => '01HZCATALOG00000000000000',
            'stack_definition_id' => '01HZSTACK0000000000000000',
            'version' => '2026.05',
            'state' => 'published',
            'summary' => 'Initial RackLab MVP template.',
            'published_at' => '2026-05-27T19:00:00.000000Z',
            'stack_definition' => [
                'id' => '01HZSTACK0000000000000000',
                'name' => 'Kali Workstation',
                'slug' => 'kali-workstation',
                'definition' => [
                    'version' => 1,
                    'components' => [
                        [
                            'key' => 'vm',
                            'kind' => 'vm',
                            'image' => 'kali-2026.1',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courseExample(): array
    {
        return [
            'id' => '01HZCOURSE0000000000000000',
            'name' => 'SEC-101',
            'slug' => 'sec-101',
            'tenant_id' => '01HZTENANT0000000000000000',
            'description' => 'Introductory security lab course.',
            'sharing_scope' => 'tenant_local',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectExample(): array
    {
        return [
            'id' => '01HZPROJECT000000000000000',
            'name' => 'Ada Lovelace Personal Project',
            'slug' => 'personal-17',
            'tenant_id' => '01HZTENANT0000000000000000',
            'is_personal_default' => true,
            'sharing_scope' => 'tenant_local',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectSshKeyExample(): array
    {
        return [
            'id' => '01HZSSHKEY000000000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'name' => 'Ada laptop',
            'key_type' => 'ssh-ed25519',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIRackLabUserKey ada@example',
            'fingerprint' => 'SHA256:exampleProjectKeyFingerprint',
            'metadata' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stackDefinitionExample(): array
    {
        return [
            'id' => '01HZSTACK0000000000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'name' => 'Default',
            'slug' => 'default',
            'scope' => 'project_local',
            'is_reserved_default' => true,
            'definition' => [
                'version' => 1,
                'components' => [
                    [
                        'key' => 'vm',
                        'kind' => 'vm',
                        'image' => 'debian-12-template',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deploymentExample(): array
    {
        return [
            'id' => '01HZDEPLOYMENT000000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'stack_definition_id' => '01HZSTACK0000000000000000',
            'name' => 'kali-workstation',
            'state' => 'running',
            'provider' => 'proxmox',
            'lease_expires_at' => '2026-05-28T21:00:00.000000Z',
            'resources' => [
                [
                    'id' => '01HZRESOURCE00000000000000',
                    'component_key' => 'vm',
                    'kind' => 'vm',
                    'state' => 'running',
                    'provider' => 'proxmox',
                    'provider_resource_id' => 'pve/node-a/qemu/1201',
                    'networks' => [
                        [
                            'id' => '01HZNETBINDING00000000000',
                            'nic_key' => 'eth0',
                            'offering_id' => '01HZNETOFFERING000000000',
                            'offering_slug' => 'private-isolated',
                            'reachability' => 'isolated_no_ingress',
                            'state' => 'attached',
                            'provider' => 'proxmox',
                            'provider_binding' => [
                                'network_type' => 'bridge',
                                'external_id' => 'vmbr100',
                                'bridge' => 'vmbr100',
                            ],
                            'management_host' => null,
                            'management_port' => null,
                        ],
                    ],
                ],
            ],
            'operation' => [
                'id' => '01HZOPERATION000000000000',
                'kind' => 'deploy',
                'state' => 'succeeded',
                'idempotency_key' => 'deploy-2026-05-27-ada',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function networkOfferingExample(): array
    {
        return [
            'id' => '01HZNETOFFERING000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'name' => 'Private isolated',
            'slug' => 'private-isolated',
            'offering_type' => 'private-isolated',
            'reachability' => 'isolated_no_ingress',
            'metadata' => [],
            'provider_network' => [
                'id' => '01HZPROVIDERNETWORK000000',
                'name' => 'Isolated bridge',
                'slug' => 'isolated-bridge',
                'provider' => 'proxmox',
                'provider_cluster' => 'default',
                'network_type' => 'bridge',
                'external_id' => 'vmbr100',
                'bridge' => 'vmbr100',
                'vlan_tag' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function networkExample(): array
    {
        return [
            'id' => '01HZNETWORK0000000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'network_offering_id' => '01HZNETOFFERING000000000',
            'offering_slug' => 'private-nat',
            'name' => 'Student NAT network',
            'slug' => 'student-nat',
            'state' => 'active',
            'provider' => 'fake',
            'reachability' => 'nat_from_management',
            'metadata' => [
                'offering_type' => 'private-nat',
            ],
            'subnets' => [
                [
                    'id' => '01HZSUBNET00000000000000',
                    'subnet_pool_id' => '01HZSUBNETPOOL000000000',
                    'cidr' => '10.42.0.0/24',
                    'ip_version' => 4,
                    'gateway_ip' => '10.42.0.1',
                    'dhcp_enabled' => true,
                    'allocation_pools' => [],
                    'dns_nameservers' => ['1.1.1.1'],
                    'host_routes' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function routerExample(): array
    {
        return [
            'id' => '01HZROUTER00000000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'name' => 'Lab Router',
            'slug' => 'lab-router',
            'state' => 'active',
            'provider' => 'fake',
            'provider_router_id' => 'fake-router-01HZROUTER00000000000000',
            'metadata' => [],
            'interfaces' => [
                [
                    'id' => '01HZROUTERIFACELEFT00000',
                    'network_id' => '01HZNETWORKLEFT000000000',
                    'network_slug' => 'left-net',
                    'subnet_id' => '01HZSUBNETLEFT0000000000',
                    'subnet_cidr' => '10.90.0.0/24',
                    'interface_ip' => null,
                    'state' => 'active',
                    'provider_binding' => [
                        'provider' => 'fake',
                        'mode' => 'fake-sdn-router',
                    ],
                ],
                [
                    'id' => '01HZROUTERIFACERIGHT0000',
                    'network_id' => '01HZNETWORKRIGHT00000000',
                    'network_slug' => 'right-net',
                    'subnet_id' => '01HZSUBNETRIGHT000000000',
                    'subnet_cidr' => '10.91.0.0/24',
                    'interface_ip' => null,
                    'state' => 'active',
                    'provider_binding' => [
                        'provider' => 'fake',
                        'mode' => 'fake-sdn-router',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function floatingIpExample(): array
    {
        return [
            'id' => '01HZFLOATINGIP0000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'floating_ip_pool_id' => '01HZFIPPOOL000000000000',
            'pool_slug' => 'public-test-pool',
            'deployment_network_binding_id' => '01HZNETBINDING000000000',
            'address' => '198.51.100.1',
            'state' => 'allocated',
            'provider' => 'fake',
            'provider_binding' => [
                'provider' => 'fake',
                'mode' => 'fake-floating-ip',
            ],
            'metadata' => [],
            'released_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function securityGroupExample(): array
    {
        return [
            'id' => '01HZSECURITYGROUP0000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'name' => 'Web security group',
            'slug' => 'web-sg',
            'state' => 'active',
            'provider' => 'fake',
            'provider_security_group_id' => 'fake-sg-01HZSECURITYGROUP0000000',
            'metadata' => [],
            'rules' => [
                [
                    'id' => '01HZSGRULE0000000000000',
                    'position' => 1,
                    'direction' => 'ingress',
                    'protocol' => 'tcp',
                    'ethertype' => 'IPv4',
                    'port_min' => 443,
                    'port_max' => 443,
                    'remote_cidr' => '0.0.0.0/0',
                    'state' => 'active',
                    'provider_rule_id' => 'fake-sg-rule-01HZSGRULE0000000000000',
                    'provider_binding' => [
                        'provider' => 'fake',
                        'mode' => 'fake-firewall-rule',
                        'revision' => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerDriftExample(): array
    {
        return [
            'id' => '01HZPROVIDERDRIFT00000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'provider' => 'fake',
            'resource_type' => 'security_group',
            'resource_id' => '01HZSECURITYGROUP0000000',
            'resource_label' => 'Web security group',
            'state' => 'repaired',
            'expected_state' => [
                'state' => 'active',
                'rules' => [
                    [
                        'direction' => 'ingress',
                        'protocol' => 'tcp',
                        'port_min' => 443,
                    ],
                ],
            ],
            'observed_state' => [
                'state' => 'active',
                'rules' => [
                    [
                        'direction' => 'ingress',
                        'protocol' => 'tcp',
                        'port_min' => 8443,
                    ],
                ],
            ],
            'drift' => [
                [
                    'path' => 'rules.0.port_min',
                    'expected' => 443,
                    'observed' => 8443,
                ],
            ],
            'detected_at' => '2026-05-28T22:00:00.000000Z',
            'resolved_at' => '2026-05-28T22:05:00.000000Z',
            'resolved_by_id' => 17,
            'resolution' => 'repair',
            'metadata' => [
                'source' => 'provider-drift-detector',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function consoleGrantExample(): array
    {
        return [
            'grant_id' => '01HZCONSOLEGRANT0000000000',
            'deployment_id' => '01HZDEPLOYMENT000000000000',
            'console_kind' => 'vnc',
            'jwt' => 'eyJhbGciOiJSUzI1NiIsImtpZCI6IjAxSFpKV1RLSUQwMDAwMDAwMDAwIn0.payload.signature',
            'kid' => '01HZJWTKID000000000000000',
            'expires_at' => '2026-05-28T12:05:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cloudInitExample(): array
    {
        return [
            'deployment_id' => '01HZDEPLOYMENT000000000000',
            'script_version_id' => '01HZVERSION00000000000000',
            'project_ssh_key_ids' => ['01HZSSHKEY000000000000000'],
            'phone_home_url' => 'https://racklab.example/api/v1/provisioning/host-keys/plain-phone-home-token',
            'host_key_phone_home_token_id' => '01HZPHONEHOME000000000000',
            'rendered_cloud_init' => "#cloud-config\nphone_home: https://racklab.example/api/v1/provisioning/host-keys/plain-phone-home-token\n",
            'rendered_redacted' => "#cloud-config\nphone_home: https://racklab.example/api/v1/provisioning/host-keys/[redacted-phone-home-token]\n",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenGrantExample(bool $includeSecret): array
    {
        $grant = [
            'id' => '01HZTOKEN0000000000000000',
            'name' => 'Dashboard CLI',
            'track' => 'track_b',
            'tenant_id' => '01HZTENANT0000000000000000',
            'resource_type' => 'project',
            'resource_id' => '01HZPROJECT000000000000000',
            'abilities' => ['project.read', 'deployment.read'],
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
        ];

        if ($includeSecret) {
            $grant['plain_text_token'] = 'racklab_pat_example_plaintext';
            $grant['authorization_header'] = 'Token racklab_pat_example_plaintext';
        }

        return $grant;
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptExample(): array
    {
        return [
            'id' => '01HZSCRIPT000000000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'name' => 'Connectivity Check',
            'slug' => 'connectivity-check',
            'runner_kind' => 'ansible',
            'state' => 'draft',
            'metadata' => [],
            'current_version' => [
                'id' => '01HZVERSION00000000000000',
                'script_id' => '01HZSCRIPT000000000000000',
                'version_number' => 1,
                'command' => ['ansible-playbook', 'site.yml'],
                'source' => "- hosts: all\n  tasks: []\n",
                'executable_hash' => 'sha256:example',
                'metadata' => ['source' => 'api'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptApprovalExample(): array
    {
        return [
            'id' => '01HZAPPROVAL0000000000000',
            'script_id' => '01HZSCRIPT000000000000000',
            'script_version_id' => '01HZVERSION00000000000000',
            'scope_type' => 'project',
            'scope_id' => '01HZPROJECT000000000000000',
            'state' => 'active',
            'invalidated_at' => null,
            'invalidation_reason' => null,
            'metadata' => ['approved_by' => 'instructor'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptRunExample(): array
    {
        return [
            'id' => '01HZRUN000000000000000000',
            'tenant_id' => '01HZTENANT0000000000000000',
            'project_id' => '01HZPROJECT000000000000000',
            'script_id' => '01HZSCRIPT000000000000000',
            'script_version_id' => '01HZVERSION00000000000000',
            'deployment_id' => null,
            'deployment_resource_id' => null,
            'runner_kind' => 'ansible',
            'state' => 'succeeded',
            'command' => ['ansible-playbook', 'site.yml'],
            'exit_code' => 0,
            'metadata' => ['runtime' => 'fake'],
            'artifacts' => [
                [
                    'id' => '01HZARTIFACT000000000000',
                    'kind' => 'script_log',
                    'content_type' => 'application/json',
                    'size_bytes' => 56,
                    'sha256' => str_repeat('a', 64),
                    'purpose' => 'ansible_result',
                    'quarantined' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForValue(mixed $value): array
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return [
                    'type' => 'array',
                    'items' => $value === [] ? ['type' => 'object'] : $this->schemaForValue($value[0]),
                ];
            }

            $properties = [];
            $required = [];

            foreach ($value as $key => $item) {
                if (! is_string($key)) {
                    continue;
                }

                $properties[$key] = $this->schemaForValue($item);
                $required[] = $key;
            }

            return [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
            ];
        }

        return match (true) {
            is_bool($value) => ['type' => 'boolean'],
            is_int($value) => ['type' => 'integer'],
            is_float($value) => ['type' => 'number'],
            $value === null => ['type' => 'null'],
            default => ['type' => 'string'],
        };
    }
}
