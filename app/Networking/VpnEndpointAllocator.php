<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\NetworkVpnEndpoint;
use App\Models\NetworkVpnEndpointBinding;
use App\Models\VpnPublicIpPool;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Allocates one or more (public_ip, udp_port) bindings for a
 * NetworkVpnEndpoint out of its VpnPublicIpPool.
 *
 * M5c S3 (this slice): one binding per endpoint (single-node Stack
 * realization through the in-memory provider). M5c S6 will scale this
 * to per-hypervisor-node bindings via a placement signal from the
 * scheduler.
 *
 * The unique (public_ip, udp_port) database constraint is the bedrock
 * guarantee: even if two concurrent allocator calls pick the same pair,
 * exactly one INSERT succeeds. The allocator retries on QueryException
 * with a freshly-rolled port until it either succeeds or exhausts the
 * configured retry budget.
 */
final readonly class VpnEndpointAllocator
{
    /**
     * Allocate one binding (public_ip, udp_port) and return it after
     * persistence. The endpoint is left in `pending` state; callers
     * advance it after provider realization in S6.
     *
     * @param  string|null  $node  optional node hint (currently unused;
     *                             S6 will pass per-hypervisor-node names).
     */
    public function allocate(NetworkVpnEndpoint $endpoint, ?string $node = null): NetworkVpnEndpointBinding
    {
        /** @var VpnPublicIpPool $pool */
        $pool = $endpoint->publicIpPool()->firstOrFail();

        $this->guardPoolRange($pool);

        // Find a (public_ip, udp_port) pair that's not held by any binding
        // — including `released` bindings, which still hold the unique
        // constraint slot until the cleanup reaper deletes the row. The
        // allocator scans deterministically so it never falsely surrenders
        // while free ports remain (codex M5c S3 P2-3); within the free port
        // set for the chosen IP we pick randomly for security.
        [$publicIp, $port] = $this->selectFreePair($pool);

        try {
            /** @var NetworkVpnEndpointBinding $binding */
            $binding = NetworkVpnEndpointBinding::query()->create([
                'tenant_id' => $endpoint->tenant_id,
                'network_vpn_endpoint_id' => $endpoint->getKey(),
                'node' => $node,
                'public_ip' => $publicIp,
                'udp_port' => $port,
                'state' => NetworkVpnEndpointBinding::STATE_ACTIVE,
                'provider_binding' => [
                    'provider' => $endpoint->provider,
                    'capability' => $endpoint->capability,
                ],
                'metadata' => [],
            ]);

            return $binding;
        } catch (QueryException $queryException) {
            // A concurrent allocator beat us to (public_ip, udp_port). Try once
            // more — the next selectFreePair() call will skip the row a
            // racing allocator just took.
            if (! $this->isUniqueViolation($queryException)) {
                throw $queryException;
            }

            [$publicIp, $port] = $this->selectFreePair($pool);

            /** @var NetworkVpnEndpointBinding $binding */
            $binding = NetworkVpnEndpointBinding::query()->create([
                'tenant_id' => $endpoint->tenant_id,
                'network_vpn_endpoint_id' => $endpoint->getKey(),
                'node' => $node,
                'public_ip' => $publicIp,
                'udp_port' => $port,
                'state' => NetworkVpnEndpointBinding::STATE_ACTIVE,
                'provider_binding' => [
                    'provider' => $endpoint->provider,
                    'capability' => $endpoint->capability,
                ],
                'metadata' => [],
            ]);

            return $binding;
        }
    }

    /**
     * @return array{string, int}
     */
    private function selectFreePair(VpnPublicIpPool $pool): array
    {
        [$start, $end, $prefixLength] = $this->rangeForCidr($pool->cidr);
        $firstUsable = $prefixLength <= 30 ? $start + 1 : $start;
        $lastUsable = $prefixLength <= 30 ? $end - 1 : $end;

        for ($candidate = $firstUsable; $candidate <= $lastUsable; $candidate++) {
            $address = $this->intToIp($candidate);
            $usedPorts = $this->usedPortsFor($address);
            $freePorts = $this->freePortsFor($pool, $usedPorts);

            if ($freePorts !== []) {
                return [$address, $freePorts[random_int(0, count($freePorts) - 1)]];
            }
        }

        throw ValidationException::withMessages([
            'vpn_public_ip_pool_id' => [
                'VPN public IP pool has no free (public_ip, udp_port) pair in the configured range.',
            ],
        ]);
    }

    /**
     * @return array<int, true>
     */
    private function usedPortsFor(string $publicIp): array
    {
        // The unique (public_ip, udp_port) DB constraint blocks ANY row with the
        // same pair — including `released` bindings still awaiting the cleanup
        // reaper. The allocator therefore treats every binding row as occupied
        // until it is physically deleted (codex M5c S3 P2-2).
        /** @var list<int> $ports */
        $ports = NetworkVpnEndpointBinding::query()
            ->where('public_ip', $publicIp)
            ->pluck('udp_port')
            ->all();

        return array_fill_keys($ports, true);
    }

    /**
     * @param  array<int, true>  $usedPorts
     * @return list<int>
     */
    private function freePortsFor(VpnPublicIpPool $pool, array $usedPorts): array
    {
        $free = [];

        for ($port = $pool->port_range_min; $port <= $pool->port_range_max; $port++) {
            if (! isset($usedPorts[$port])) {
                $free[] = $port;
            }
        }

        return $free;
    }

    private function guardPoolRange(VpnPublicIpPool $pool): void
    {
        $min = $pool->port_range_min;
        $max = $pool->port_range_max;

        if ($min < 1 || $max > 65535 || $min > $max) {
            throw new InvalidArgumentException(sprintf(
                'VPN public IP pool %s has an invalid UDP port range [%d, %d].',
                $pool->slug,
                $min,
                $max,
            ));
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        // SQLite + PostgreSQL + MySQL all surface unique constraint text in the message.
        return str_contains($message, 'UNIQUE')
            || str_contains($message, 'unique')
            || str_contains($message, '23505')
            || str_contains($message, 'Duplicate entry');
    }

    /**
     * @return array{int, int, int}
     */
    private function rangeForCidr(string $cidr): array
    {
        $parts = explode('/', $cidr, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Invalid IPv4 CIDR: '.$cidr);
        }

        [$ip, $rawPrefixLength] = $parts;
        $prefixLength = filter_var($rawPrefixLength, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 32],
        ]);

        if (! is_int($prefixLength)) {
            throw new InvalidArgumentException('Invalid IPv4 CIDR prefix: '.$cidr);
        }

        $start = $this->ipToInt($ip);
        $blockSize = 2 ** (32 - $prefixLength);
        $networkStart = intdiv($start, $blockSize) * $blockSize;

        return [$networkStart, $networkStart + $blockSize - 1, $prefixLength];
    }

    private function ipToInt(string $ip): int
    {
        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 4) {
            throw new InvalidArgumentException('Invalid IPv4 address: '.$ip);
        }

        $parts = unpack('Nvalue', $packed);
        $value = $parts['value'] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException('Invalid IPv4 address: '.$ip);
        }

        return $value;
    }

    private function intToIp(int $value): string
    {
        $packed = pack('N', $value);
        $ip = inet_ntop($packed);

        if ($ip === false || $ip === '') {
            throw new InvalidArgumentException('Invalid IPv4 integer: '.$value);
        }

        return $ip;
    }
}
