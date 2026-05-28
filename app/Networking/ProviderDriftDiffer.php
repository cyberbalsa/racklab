<?php

declare(strict_types=1);

namespace App\Networking;

final readonly class ProviderDriftDiffer
{
    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $observed
     * @return list<array{path: string, expected: mixed, observed: mixed}>
     */
    public function diff(array $expected, array $observed): array
    {
        return $this->diffValue($this->canonicalize($expected), $this->canonicalize($observed), '');
    }

    /**
     * @return list<array{path: string, expected: mixed, observed: mixed}>
     */
    private function diffValue(mixed $expected, mixed $observed, string $path): array
    {
        if (is_array($expected) && is_array($observed)) {
            return $this->diffArray($expected, $observed, $path);
        }

        if ($expected === $observed) {
            return [];
        }

        return [[
            'path' => $path,
            'expected' => $expected,
            'observed' => $observed,
        ]];
    }

    /**
     * @param  array<int|string, mixed>  $expected
     * @param  array<int|string, mixed>  $observed
     * @return list<array{path: string, expected: mixed, observed: mixed}>
     */
    private function diffArray(array $expected, array $observed, string $path): array
    {
        if (array_is_list($expected) || array_is_list($observed)) {
            return $this->diffList(array_values($expected), array_values($observed), $path);
        }

        $keys = array_values(array_unique([
            ...array_keys($expected),
            ...array_keys($observed),
        ]));
        sort($keys);

        $differences = [];

        foreach ($keys as $key) {
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }

            $childPath = $this->joinPath($path, (string) $key);
            $hasExpected = array_key_exists($key, $expected);
            $hasObserved = array_key_exists($key, $observed);

            if (! $hasExpected || ! $hasObserved) {
                $differences[] = [
                    'path' => $childPath,
                    'expected' => $hasExpected ? $expected[$key] : null,
                    'observed' => $hasObserved ? $observed[$key] : null,
                ];

                continue;
            }

            array_push(
                $differences,
                ...$this->diffValue($expected[$key], $observed[$key], $childPath),
            );
        }

        return $differences;
    }

    /**
     * @param  list<mixed>  $expected
     * @param  list<mixed>  $observed
     * @return list<array{path: string, expected: mixed, observed: mixed}>
     */
    private function diffList(array $expected, array $observed, string $path): array
    {
        $differences = [];
        $count = max(count($expected), count($observed));

        for ($index = 0; $index < $count; $index++) {
            $childPath = $this->joinPath($path, (string) $index);
            $hasExpected = array_key_exists($index, $expected);
            $hasObserved = array_key_exists($index, $observed);

            if (! $hasExpected || ! $hasObserved) {
                $differences[] = [
                    'path' => $childPath,
                    'expected' => $hasExpected ? $expected[$index] : null,
                    'observed' => $hasObserved ? $observed[$index] : null,
                ];

                continue;
            }

            array_push(
                $differences,
                ...$this->diffValue($expected[$index], $observed[$index], $childPath),
            );
        }

        return $differences;
    }

    private function joinPath(string $path, string $key): string
    {
        return $path === '' ? $key : $path.'.'.$key;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }
}
