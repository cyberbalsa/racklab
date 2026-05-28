<?php

declare(strict_types=1);

namespace App\Quota;

use App\Models\StackDefinition;

final readonly class StackQuotaEstimator
{
    /**
     * @return array<string, int>
     */
    public function estimate(StackDefinition $stack, string $operationKind, bool $newDeployment): array
    {
        $dimensions = [];

        if (in_array($operationKind, ['deploy', 'add_vm'], true)) {
            $dimensions['vcpu'] = $operationKind === 'deploy'
                ? $this->deploymentVcpus($stack)
                : $this->singleVmVcpus($stack);
        }

        if ($newDeployment) {
            $dimensions['concurrent_deployments'] = 1;
        }

        return array_filter($dimensions, static fn (int $quantity): bool => $quantity > 0);
    }

    private function deploymentVcpus(StackDefinition $stack): int
    {
        $components = $this->components($stack);

        if ($components === []) {
            return 1;
        }

        $vcpus = 0;

        foreach ($components as $component) {
            if (! $this->isVmComponent($component)) {
                continue;
            }

            $vcpus += $this->componentVcpus($component);
        }

        return $vcpus > 0 ? $vcpus : 1;
    }

    private function singleVmVcpus(StackDefinition $stack): int
    {
        foreach ($this->components($stack) as $component) {
            if ($this->isVmComponent($component)) {
                return $this->componentVcpus($component);
            }
        }

        return 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function components(StackDefinition $stack): array
    {
        $definition = $stack->definition ?? [];
        $components = $definition['components'] ?? null;

        if (! is_array($components)) {
            return [];
        }

        $normalized = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $normalized[] = $this->stringKeyedArray($component);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function isVmComponent(array $component): bool
    {
        $kind = $component['kind'] ?? null;

        return ! is_string($kind) || $kind === '' || $kind === 'vm';
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function componentVcpus(array $component): int
    {
        foreach ([
            $component['resources'] ?? null,
            $component['quota'] ?? null,
            $component,
        ] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $value = $this->resourceValue($this->stringKeyedArray($candidate));

            if ($value > 0) {
                return $value;
            }
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function resourceValue(array $values): int
    {
        foreach (['vcpus', 'vcpu', 'cpus', 'cpu'] as $key) {
            $value = $values[$key] ?? null;

            if (is_int($value)) {
                return max(0, $value);
            }

            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
