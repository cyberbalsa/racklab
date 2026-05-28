<?php

declare(strict_types=1);

namespace App\Networking;

use App\Domain\Tenancy\TenantContext;
use App\Models\Subnet;
use App\Models\SubnetPool;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final readonly class SubnetPoolAllocator
{
    public function allocate(SubnetPool $pool, TenantContext $context, ?int $requestedPrefixLength): string
    {
        if ($pool->ip_version !== 4) {
            throw ValidationException::withMessages([
                'subnet.subnet_pool_id' => ['Only IPv4 subnet pools are supported by this API slice.'],
            ]);
        }

        $prefixLength = $requestedPrefixLength ?? $pool->default_prefix_length;
        [$poolStart, $poolEnd, $poolPrefixLength] = $this->rangeForCidr($pool->cidr);

        if (
            $prefixLength < $pool->min_prefix_length
            || $prefixLength > $pool->max_prefix_length
            || $prefixLength < $poolPrefixLength
        ) {
            throw ValidationException::withMessages([
                'subnet.prefix_length' => [
                    sprintf(
                        'Prefix length must be between %d and %d and must fit inside %s.',
                        max($pool->min_prefix_length, $poolPrefixLength),
                        $pool->max_prefix_length,
                        $pool->cidr,
                    ),
                ],
            ]);
        }

        $blockSize = 2 ** (32 - $prefixLength);
        $candidate = $this->alignUp($poolStart, $blockSize);
        $usedRanges = $this->usedRanges($context, $poolStart, $poolEnd);

        while ($candidate + $blockSize - 1 <= $poolEnd) {
            $candidateEnd = $candidate + $blockSize - 1;

            if (! $this->overlapsAny($candidate, $candidateEnd, $usedRanges)) {
                return $this->intToIp($candidate).'/'.$prefixLength;
            }

            $candidate += $blockSize;
        }

        throw ValidationException::withMessages([
            'subnet.subnet_pool_id' => ['Subnet pool has no free CIDR ranges for the requested prefix length.'],
        ]);
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

    /**
     * @return list<array{int, int}>
     */
    private function usedRanges(TenantContext $context, int $poolStart, int $poolEnd): array
    {
        /** @var list<string> $cidrs */
        $cidrs = Subnet::query()
            ->where('tenant_id', $context->activeTenantId)
            ->where('ip_version', 4)
            ->pluck('cidr')
            ->all();

        $ranges = [];

        foreach ($cidrs as $cidr) {
            [$start, $end] = $this->rangeForCidr($cidr);

            if ($start <= $poolEnd && $end >= $poolStart) {
                $ranges[] = [$start, $end];
            }
        }

        return $ranges;
    }

    /**
     * @param  list<array{int, int}>  $ranges
     */
    private function overlapsAny(int $start, int $end, array $ranges): bool
    {
        foreach ($ranges as [$usedStart, $usedEnd]) {
            if ($start <= $usedEnd && $end >= $usedStart) {
                return true;
            }
        }

        return false;
    }

    private function alignUp(int $value, int $blockSize): int
    {
        if ($blockSize <= 0) {
            throw new RuntimeException('CIDR block size must be positive.');
        }

        $remainder = $value % $blockSize;

        if ($remainder === 0) {
            return $value;
        }

        return $value + ($blockSize - $remainder);
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
        return long2ip($value);
    }
}
