<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\DeploymentResource;
use App\Models\StackDefinition;

final readonly class StackNetworkSpecExtractor
{
    /**
     * @return list<StackNetworkSpec>
     */
    public function forStack(StackDefinition $stack): array
    {
        $specs = [];

        foreach ($this->components($stack) as $index => $component) {
            $specs = [
                ...$specs,
                ...$this->specsForComponent(
                    component: $component,
                    componentKey: $this->stringValue($component['key'] ?? null, sprintf('component-%d', $index + 1)),
                ),
            ];
        }

        return $specs;
    }

    /**
     * @return list<StackNetworkSpec>
     */
    public function forResource(StackDefinition $stack, DeploymentResource $resource): array
    {
        return $this->specsForComponent(
            component: $this->componentForResource($stack, $resource),
            componentKey: $resource->component_key,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function components(StackDefinition $stack): array
    {
        $definition = $stack->definition ?? [];
        $components = is_array($definition['components'] ?? null) ? $definition['components'] : [];

        return array_values(array_filter(
            array_map($this->stringKeyedArray(...), $components),
            static fn (array $component): bool => $component !== [],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function componentForResource(StackDefinition $stack, DeploymentResource $resource): array
    {
        $firstVm = [];

        foreach ($this->components($stack) as $component) {
            $key = $component['key'] ?? null;

            if ($key === $resource->component_key) {
                return $component;
            }

            if ($firstVm === [] && (($component['kind'] ?? 'vm') === 'vm')) {
                $firstVm = $component;
            }
        }

        return $firstVm;
    }

    /**
     * @param  array<string, mixed>  $component
     * @return list<StackNetworkSpec>
     */
    private function specsForComponent(array $component, string $componentKey): array
    {
        $networks = $component['networks'] ?? [];

        if (! is_array($networks)) {
            return [];
        }

        $specs = [];

        foreach (array_values($networks) as $index => $network) {
            $network = $this->stringKeyedArray($network);

            if ($network === []) {
                continue;
            }

            $key = $network['key'] ?? null;
            $offeringId = $network['offering_id'] ?? null;
            $offeringSlug = $network['offering_slug'] ?? $network['offering'] ?? null;

            $specs[] = new StackNetworkSpec(
                componentKey: $componentKey,
                key: $this->stringValue($key, sprintf('eth%d', $index)),
                offeringId: $this->nullableString($offeringId),
                offeringSlug: $this->nullableString($offeringSlug),
            );
        }

        return $specs;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
