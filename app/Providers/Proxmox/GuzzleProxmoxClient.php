<?php

declare(strict_types=1);

namespace App\Providers\Proxmox;

use App\Providers\Proxmox\Contracts\ProxmoxClientContract;
use App\Providers\Proxmox\Exceptions\ProviderAuthError;
use App\Providers\Proxmox\Exceptions\ProviderBug;
use App\Providers\Proxmox\Exceptions\ProviderNotFound;
use App\Providers\Proxmox\Exceptions\ProviderTransient;
use App\Providers\Proxmox\Models\ProxmoxTaskStatus;
use App\Providers\Proxmox\Models\ProxmoxTermProxyRequest;
use App\Providers\Proxmox\Models\ProxmoxTermProxyTicket;
use App\Providers\Proxmox\Models\ProxmoxVersion;
use App\Providers\Proxmox\Models\ProxmoxVmCloneRequest;
use App\Providers\Proxmox\Models\ProxmoxVmDeleteRequest;
use App\Providers\Proxmox\Models\ProxmoxVmPowerRequest;
use App\Providers\Proxmox\Models\ProxmoxVncProxyRequest;
use App\Providers\Proxmox\Models\ProxmoxVncTicket;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

final readonly class GuzzleProxmoxClient implements ProxmoxClientContract
{
    public function __construct(
        private ProxmoxEndpointConfig $config,
        private ClientInterface $http,
    ) {}

    public function version(): ProxmoxVersion
    {
        $data = $this->json('GET', '/api2/json/version');

        if (! is_array($data)) {
            throw new ProviderBug('Proxmox version response was not an object.');
        }

        return ProxmoxVersion::fromStrings(
            version: is_string($data['version'] ?? null) ? $data['version'] : '0.0.0',
            release: is_string($data['release'] ?? null) ? $data['release'] : 'unknown',
            repoId: is_string($data['repoid'] ?? null) ? $data['repoid'] : null,
        );
    }

    public function cloneVm(ProxmoxVmCloneRequest $request): string
    {
        $data = $this->json(
            method: 'POST',
            path: sprintf(
                '/api2/json/nodes/%s/qemu/%d/clone',
                rawurlencode($request->node),
                $request->templateVmid,
            ),
            options: ['form_params' => $request->formParams()],
        );

        if (! is_string($data)) {
            throw new ProviderBug('Proxmox clone response did not contain a UPID.');
        }

        return $data;
    }

    public function deleteVm(ProxmoxVmDeleteRequest $request): string
    {
        $data = $this->json(
            method: 'DELETE',
            path: sprintf(
                '/api2/json/nodes/%s/qemu/%d',
                rawurlencode($request->node),
                $request->vmid,
            ),
            options: ['query' => $request->query()],
        );

        if (! is_string($data)) {
            throw new ProviderBug('Proxmox delete response did not contain a UPID.');
        }

        return $data;
    }

    public function powerVm(ProxmoxVmPowerRequest $request): string
    {
        $data = $this->json(
            method: 'POST',
            path: sprintf(
                '/api2/json/nodes/%s/qemu/%d/status/%s',
                rawurlencode($request->node),
                $request->vmid,
                rawurlencode($request->action),
            ),
        );

        if (! is_string($data)) {
            throw new ProviderBug('Proxmox power response did not contain a UPID.');
        }

        return $data;
    }

    public function vncProxy(ProxmoxVncProxyRequest $request): ProxmoxVncTicket
    {
        $data = $this->json(
            method: 'POST',
            path: sprintf(
                '/api2/json/nodes/%s/qemu/%d/vncproxy',
                rawurlencode($request->node),
                $request->vmid,
            ),
            options: ['form_params' => $request->formParams()],
        );

        if (! is_array($data)) {
            throw new ProviderBug('Proxmox vncproxy response was not an object.');
        }

        return new ProxmoxVncTicket(
            ticket: is_string($data['ticket'] ?? null) ? $data['ticket'] : throw new ProviderBug('Proxmox vncproxy response missing ticket.'),
            cert: is_string($data['cert'] ?? null) ? $data['cert'] : throw new ProviderBug('Proxmox vncproxy response missing cert.'),
            port: is_int($data['port'] ?? null) ? $data['port'] : (is_numeric($data['port'] ?? null) ? (int) $data['port'] : throw new ProviderBug('Proxmox vncproxy response missing port.')),
            upid: is_string($data['upid'] ?? null) ? $data['upid'] : throw new ProviderBug('Proxmox vncproxy response missing upid.'),
            user: is_string($data['user'] ?? null) ? $data['user'] : throw new ProviderBug('Proxmox vncproxy response missing user.'),
            password: is_string($data['password'] ?? null) ? $data['password'] : null,
        );
    }

    public function termProxy(ProxmoxTermProxyRequest $request): ProxmoxTermProxyTicket
    {
        $data = $this->json(
            method: 'POST',
            path: sprintf(
                '/api2/json/nodes/%s/qemu/%d/termproxy',
                rawurlencode($request->node),
                $request->vmid,
            ),
            options: ['form_params' => $request->formParams()],
        );

        if (! is_array($data)) {
            throw new ProviderBug('Proxmox termproxy response was not an object.');
        }

        return new ProxmoxTermProxyTicket(
            ticket: is_string($data['ticket'] ?? null) ? $data['ticket'] : throw new ProviderBug('Proxmox termproxy response missing ticket.'),
            port: is_int($data['port'] ?? null) ? $data['port'] : (is_numeric($data['port'] ?? null) ? (int) $data['port'] : throw new ProviderBug('Proxmox termproxy response missing port.')),
            upid: is_string($data['upid'] ?? null) ? $data['upid'] : throw new ProviderBug('Proxmox termproxy response missing upid.'),
            user: is_string($data['user'] ?? null) ? $data['user'] : throw new ProviderBug('Proxmox termproxy response missing user.'),
        );
    }

    public function taskStatus(string $node, string $upid): ProxmoxTaskStatus
    {
        $data = $this->json('GET', sprintf(
            '/api2/json/nodes/%s/tasks/%s/status',
            rawurlencode($node),
            rawurlencode($upid),
        ));

        if (! is_array($data)) {
            throw new ProviderBug('Proxmox task status response was not an object.');
        }

        return new ProxmoxTaskStatus(
            upid: $upid,
            node: $node,
            status: is_string($data['status'] ?? null) ? $data['status'] : 'unknown',
            exitStatus: is_string($data['exitstatus'] ?? null) ? $data['exitstatus'] : null,
        );
    }

    public function taskLog(string $node, string $upid): array
    {
        $data = $this->json('GET', sprintf(
            '/api2/json/nodes/%s/tasks/%s/log?start=0&limit=500',
            rawurlencode($node),
            rawurlencode($upid),
        ));

        if (! is_array($data)) {
            return [];
        }

        $lines = [];

        foreach ($data as $row) {
            if (is_array($row) && is_string($row['t'] ?? null)) {
                $lines[] = $row['t'];
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<mixed>|string
     */
    private function json(string $method, string $path, array $options = []): array|string
    {
        $response = $this->request($method, $path, $options);
        $this->mapStatus($response);

        try {
            $payload = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new ProviderBug('Proxmox returned invalid JSON.', $jsonException->getCode(), previous: $jsonException);
        }

        if (! is_array($payload)) {
            throw new ProviderBug('Proxmox returned a non-object JSON payload.');
        }

        $data = $payload['data'] ?? [];

        if (is_array($data) || is_string($data)) {
            return $data;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        try {
            return $this->http->request(
                $method,
                $this->config->baseUri.$path,
                array_replace_recursive($this->config->guzzleOptions(), $options),
            );
        } catch (ConnectException $connectException) {
            throw new ProviderTransient($connectException->getMessage(), $connectException->getCode(), previous: $connectException);
        } catch (GuzzleException $transferException) {
            throw new ProviderTransient($transferException->getMessage(), $transferException->getCode(), previous: $transferException);
        }
    }

    private function mapStatus(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new ProviderAuthError('Proxmox authentication failed.');
        }

        if ($status === 404) {
            throw new ProviderNotFound('Proxmox resource not found.');
        }

        if ($status >= 500) {
            throw new ProviderTransient(sprintf('Proxmox returned HTTP %d.', $status));
        }

        if ($status >= 400) {
            throw new ProviderBug(sprintf('Proxmox returned unexpected HTTP %d.', $status));
        }
    }
}
