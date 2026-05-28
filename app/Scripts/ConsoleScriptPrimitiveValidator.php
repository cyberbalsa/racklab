<?php

declare(strict_types=1);

namespace App\Scripts;

use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class ConsoleScriptPrimitiveValidator
{
    /**
     * @var list<string>
     */
    private const array RUNNER_KINDS = ['openqa', 'console_script'];

    /**
     * @var list<string>
     */
    private const array OPS = [
        'send_key',
        'type_string',
        'wait_screen',
        'assert_screen',
        'wait_serial',
        'script_run',
        'capture_screenshot',
        'capture_serial',
        'capture_artifact',
    ];

    public function assertValid(string $runnerKind, string $source): void
    {
        if (! in_array($runnerKind, self::RUNNER_KINDS, true)) {
            return;
        }

        try {
            $steps = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('Console automation source must be a JSON array of primitive steps.');
        }

        if (! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            $this->fail('Console automation source must contain at least one primitive step.');
        }

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                $this->fail(sprintf('Console automation step %d must be an object.', $index));
            }

            $this->validateStep($index, $step);
        }
    }

    /**
     * @param  array<array-key, mixed>  $step
     */
    private function validateStep(int $index, array $step): void
    {
        $op = $step['op'] ?? null;

        if (! is_string($op) || ! in_array($op, self::OPS, true)) {
            $this->fail(sprintf('Console automation step %d declares an unsupported primitive.', $index));
        }

        match ($op) {
            'send_key' => $this->requireString($index, $step, 'key'),
            'type_string' => $this->requireString($index, $step, 'text'),
            'wait_screen', 'assert_screen', 'wait_serial' => $this->requireNeedle($index, $step),
            'script_run' => $this->requireCommand($index, $step),
            'capture_artifact' => $this->requireString($index, $step, 'path'),
            'capture_screenshot', 'capture_serial' => null,
        };

        $this->validateTimeout($index, $step);
    }

    /**
     * @param  array<array-key, mixed>  $step
     */
    private function requireNeedle(int $index, array $step): void
    {
        if ($this->nonEmptyString($step['needle'] ?? null) || $this->nonEmptyString($step['text'] ?? null)) {
            return;
        }

        $this->fail(sprintf('Console automation step %d must include a needle or text value.', $index));
    }

    /**
     * @param  array<array-key, mixed>  $step
     */
    private function requireCommand(int $index, array $step): void
    {
        $command = $step['command'] ?? null;

        if (! is_array($command) || $command === []) {
            $this->fail(sprintf('Console automation step %d must include a non-empty command array.', $index));
        }

        foreach ($command as $part) {
            if (! $this->nonEmptyString($part)) {
                $this->fail(sprintf('Console automation step %d command entries must be non-empty strings.', $index));
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $step
     */
    private function requireString(int $index, array $step, string $field): void
    {
        if ($this->nonEmptyString($step[$field] ?? null)) {
            return;
        }

        $this->fail(sprintf('Console automation step %d must include a non-empty %s value.', $index, $field));
    }

    /**
     * @param  array<array-key, mixed>  $step
     */
    private function validateTimeout(int $index, array $step): void
    {
        $timeout = $step['timeout_seconds'] ?? null;

        if ($timeout === null) {
            return;
        }

        if (! is_int($timeout) || $timeout < 1 || $timeout > 3600) {
            $this->fail(sprintf('Console automation step %d timeout_seconds must be an integer between 1 and 3600.', $index));
        }
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['source' => $message]);
    }
}
