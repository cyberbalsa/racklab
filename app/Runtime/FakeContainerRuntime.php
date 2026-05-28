<?php

declare(strict_types=1);

namespace App\Runtime;

use App\Contracts\ContainerRuntime;
use App\Models\ScriptRun;
use JsonException;
use Symfony\Component\Yaml\Yaml;

final readonly class FakeContainerRuntime implements ContainerRuntime
{
    public function run(ContainerRunRequest $request): ContainerRunResult
    {
        return match ($request->scriptRun->runner_kind) {
            'network', 'ansible' => $this->ansible($request->scriptRun),
            'openqa', 'console_script' => $this->console($request->scriptRun),
            default => $this->generic($request->scriptRun),
        };
    }

    private function ansible(ScriptRun $scriptRun): ContainerRunResult
    {
        $plays = $this->ansiblePlays($scriptRun->source ?? '');
        $playCount = count($plays);
        $taskCount = $this->ansibleTaskCount($plays);
        $result = [
            'runner' => 'ansible',
            'status' => 'ok',
            'plays' => $playCount,
            'tasks' => $taskCount,
        ];

        return new ContainerRunResult(
            exitCode: 0,
            stdout: sprintf("fake ansible completed %d plays and %d tasks\n", $playCount, $taskCount),
            stderr: '',
            metadata: [
                'runtime' => 'fake',
                'runner_kind' => $scriptRun->runner_kind,
                'plays' => $playCount,
                'tasks' => $taskCount,
            ],
            artifacts: [
                new ContainerOutputArtifact(
                    kind: 'script_log',
                    purpose: 'ansible_result',
                    content: json_encode($result, JSON_THROW_ON_ERROR)."\n",
                    contentType: 'application/json',
                    filename: 'ansible-result.json',
                    metadata: ['runner' => 'ansible'],
                ),
            ],
        );
    }

    private function console(ScriptRun $scriptRun): ContainerRunResult
    {
        $steps = $this->consoleSteps($scriptRun->source ?? '');
        $serial = [];
        $artifacts = [];

        foreach ($steps as $index => $step) {
            $op = is_string($step['op'] ?? null) ? $step['op'] : 'unknown';
            $serial[] = $this->consoleSerialLine($op, $step);

            if ($op === 'capture_screenshot') {
                $name = $this->artifactName($step['name'] ?? null, 'screenshot-'.$index);
                $artifacts[] = new ContainerOutputArtifact(
                    kind: 'script_screenshot',
                    purpose: 'console_screenshot',
                    content: 'fake screenshot '.$name."\n",
                    contentType: 'image/png',
                    filename: $name.'.png',
                    metadata: ['step' => $index],
                );
            }
        }

        $serialContent = implode("\n", $serial)."\n";

        if ($this->hasCaptureSerial($steps)) {
            $artifacts[] = new ContainerOutputArtifact(
                kind: 'script_serial',
                purpose: 'console_serial',
                content: $serialContent,
                contentType: 'text/plain; charset=utf-8',
                filename: 'serial.log',
                metadata: ['steps' => count($steps)],
            );
        }

        return new ContainerRunResult(
            exitCode: 0,
            stdout: sprintf("fake console automation completed %d steps\n", count($steps)),
            stderr: '',
            metadata: [
                'runtime' => 'fake',
                'runner_kind' => $scriptRun->runner_kind,
                'console_steps_executed' => count($steps),
            ],
            artifacts: $artifacts,
        );
    }

    private function generic(ScriptRun $scriptRun): ContainerRunResult
    {
        return new ContainerRunResult(
            exitCode: 0,
            stdout: "fake script completed\n",
            stderr: '',
            metadata: [
                'runtime' => 'fake',
                'runner_kind' => $scriptRun->runner_kind,
            ],
        );
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function ansiblePlays(string $source): array
    {
        $plays = Yaml::parse($source);

        return is_array($plays) ? array_values(array_filter($plays, is_array(...))) : [];
    }

    /**
     * @param  list<array<array-key, mixed>>  $plays
     */
    private function ansibleTaskCount(array $plays): int
    {
        $count = 0;

        foreach ($plays as $play) {
            $tasks = $play['tasks'] ?? [];
            $count += is_array($tasks) ? count($tasks) : 0;
        }

        return $count;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function consoleSteps(string $source): array
    {
        try {
            $steps = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($steps) || ! array_is_list($steps)) {
            return [];
        }

        return array_values(array_filter($steps, is_array(...)));
    }

    /**
     * @param  array<array-key, mixed>  $step
     */
    private function consoleSerialLine(string $op, array $step): string
    {
        $value = $step['needle'] ?? $step['text'] ?? $step['key'] ?? $step['name'] ?? '';

        if (! is_scalar($value)) {
            return $op;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? $op : $op.':'.$stringValue;
    }

    /**
     * @param  list<array<array-key, mixed>>  $steps
     */
    private function hasCaptureSerial(array $steps): bool
    {
        foreach ($steps as $step) {
            if (is_array($step) && ($step['op'] ?? null) === 'capture_serial') {
                return true;
            }
        }

        return false;
    }

    private function artifactName(mixed $value, string $fallback): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $fallback;
    }
}
