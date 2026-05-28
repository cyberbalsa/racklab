<?php

declare(strict_types=1);

namespace App\Scripts;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class AnsiblePlaybookValidator
{
    /**
     * @var list<string>
     */
    private const array RUNNER_KINDS = ['network', 'ansible'];

    public function assertValid(string $runnerKind, string $source): void
    {
        if (! in_array($runnerKind, self::RUNNER_KINDS, true)) {
            return;
        }

        try {
            $plays = Yaml::parse($source);
        } catch (ParseException) {
            $this->fail('Ansible playbook source must be valid YAML.');
        }

        if (! is_array($plays) || ! array_is_list($plays) || $plays === []) {
            $this->fail('Ansible playbook source must be a non-empty YAML list of plays.');
        }

        foreach ($plays as $index => $play) {
            if (! is_array($play)) {
                $this->fail(sprintf('Ansible play %d must be a mapping.', $index));
            }

            $this->validatePlay($index, $play);
            $this->rejectRuntimeGalaxy($index, $play);
        }
    }

    /**
     * @param  array<array-key, mixed>  $play
     */
    private function validatePlay(int $index, array $play): void
    {
        if (! $this->nonEmptyString($play['hosts'] ?? null) && ! is_array($play['hosts'] ?? null)) {
            $this->fail(sprintf('Ansible play %d must declare hosts.', $index));
        }

        if (! array_key_exists('tasks', $play)) {
            return;
        }

        $tasks = $play['tasks'];

        if (! is_array($tasks) || ! array_is_list($tasks)) {
            $this->fail(sprintf('Ansible play %d tasks must be a list when present.', $index));
        }
    }

    /**
     * @param  array<array-key, mixed>  $play
     */
    private function rejectRuntimeGalaxy(int $index, array $play): void
    {
        if ($this->containsAnsibleGalaxy($play)) {
            $this->fail(sprintf('Ansible play %d must not invoke ansible-galaxy at runtime.', $index));
        }
    }

    private function containsAnsibleGalaxy(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains(strtolower($value), 'ansible-galaxy');
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $child) {
            if ($this->containsAnsibleGalaxy($child)) {
                return true;
            }
        }

        return false;
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
