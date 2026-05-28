<?php

declare(strict_types=1);

namespace App\Runtime;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class PodmanStaleContainerReaper
{
    public function __construct(
        private PodmanCommandBuilder $commands,
        private ContainerProcessRunner $processes,
        private int $listTimeoutSeconds = 30,
        private int $cleanupTimeoutSeconds = 30,
    ) {}

    public function reap(int $maxAgeSeconds, ?DateTimeInterface $now = null): int
    {
        if ($maxAgeSeconds < 1) {
            throw new InvalidArgumentException('The script container max age must be at least one second.');
        }

        $now ??= new DateTimeImmutable;
        $list = $this->processes->run($this->commands->listScriptContainers(), $this->listTimeoutSeconds);

        if ($list->exitCode !== 0) {
            throw new RuntimeException('Unable to list RackLab script containers for reaping.');
        }

        $reaped = 0;

        foreach ($this->staleContainerNames($list->stdout, $now->getTimestamp() - $maxAgeSeconds) as $containerName) {
            $cleanup = $this->processes->run(
                $this->commands->cleanupByName($containerName),
                $this->cleanupTimeoutSeconds,
            );

            if ($cleanup->exitCode !== 0) {
                throw new RuntimeException(sprintf('Unable to remove stale RackLab script container [%s].', $containerName));
            }

            $reaped++;
        }

        return $reaped;
    }

    /**
     * @return list<string>
     */
    private function staleContainerNames(string $podmanJson, int $cutoffTimestamp): array
    {
        $rows = $this->decodeRows($podmanJson);
        $names = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->containerName($row['Names'] ?? null);

            if ($name === null) {
                continue;
            }

            if (! str_starts_with($name, 'racklab-script-')) {
                continue;
            }

            $createdAt = $this->createdAt($row);

            if ($createdAt !== null && $createdAt <= $cutoffTimestamp) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function decodeRows(string $podmanJson): array
    {
        try {
            $rows = json_decode($podmanJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Podman returned invalid JSON while listing script containers.', $jsonException->getCode(), previous: $jsonException);
        }

        if (! is_array($rows)) {
            throw new RuntimeException('Podman returned an unexpected container list shape.');
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    private function containerName(mixed $names): ?string
    {
        if (is_string($names) && $names !== '') {
            return $names;
        }

        if (! is_array($names)) {
            return null;
        }

        $firstName = $names[0] ?? null;

        return is_string($firstName) && $firstName !== '' ? $firstName : null;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function createdAt(array $row): ?int
    {
        $labels = $row['Labels'] ?? [];

        if (is_array($labels)) {
            $timestamp = $this->timestamp($labels['racklab.created_at'] ?? null);

            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        return $this->timestamp($row['CreatedAt'] ?? $row['Created'] ?? null);
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }
}
