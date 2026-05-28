<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\FloatingIp;
use App\Models\FloatingIpPool;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class FloatingIpAllocator
{
    public function allocate(FloatingIpPool $pool): string
    {
        if ($pool->ip_version !== 4) {
            throw ValidationException::withMessages([
                'floating_ip_pool_id' => ['Only IPv4 floating IP pools are supported by this API slice.'],
            ]);
        }

        [$start, $end, $prefixLength] = $this->rangeForCidr($pool->cidr);
        $firstUsable = $prefixLength <= 30 ? $start + 1 : $start;
        $lastUsable = $prefixLength <= 30 ? $end - 1 : $end;
        $used = $this->usedAddresses($pool);

        for ($candidate = $firstUsable; $candidate <= $lastUsable; $candidate++) {
            $address = $this->intToIp($candidate);

            if (! isset($used[$address])) {
                return $address;
            }
        }

        throw ValidationException::withMessages([
            'floating_ip_pool_id' => ['Floating IP pool has no free addresses.'],
        ]);
    }

    /**
     * @return array<string, true>
     */
    private function usedAddresses(FloatingIpPool $pool): array
    {
        /** @var list<string> $addresses */
        $addresses = FloatingIp::query()
            ->where('floating_ip_pool_id', $pool->getKey())
            ->where('state', 'allocated')
            ->pluck('address')
            ->all();

        $used = [];

        foreach ($addresses as $address) {
            $used[$address] = true;
        }

        return $used;
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
        $ip = long2ip($value);

        if ($ip === false) {
            throw new InvalidArgumentException('Invalid IPv4 integer: '.$value);
        }

        return $ip;
    }
}
