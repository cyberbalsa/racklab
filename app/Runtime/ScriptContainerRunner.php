<?php

declare(strict_types=1);

namespace App\Runtime;

use App\Artifacts\ScriptLogArtifactWriter;
use App\Contracts\ContainerRuntime;
use App\Models\Artifact;
use App\Models\ScriptRun;
use Throwable;

final readonly class ScriptContainerRunner
{
    public function __construct(
        private ContainerRuntime $runtime,
        private ScriptLogArtifactWriter $artifacts,
    ) {}

    public function run(string $scriptRunId, ContainerManifest $manifest): ScriptRun
    {
        /** @var ScriptRun $scriptRun */
        $scriptRun = ScriptRun::query()->whereKey($scriptRunId)->firstOrFail();
        $scriptRun->forceFill([
            'state' => 'running',
            'started_at' => now(),
        ])->save();

        try {
            $result = $this->runtime->run(new ContainerRunRequest($scriptRun->refresh(), $manifest));
        } catch (Throwable $throwable) {
            $redaction = $this->redaction($scriptRun, '', $throwable->getMessage());
            $metadata = $this->withLogArtifacts($scriptRun, $redaction['stdout'], $redaction['stderr'], [
                ...$redaction['metadata'],
                'runtime_error' => $throwable::class,
            ]);

            $scriptRun->forceFill([
                'state' => 'failed',
                'stderr' => $redaction['stderr'],
                'exit_code' => 127,
                'finished_at' => now(),
                'metadata' => $metadata,
            ])->save();

            return $scriptRun->refresh();
        }

        $redaction = $this->redaction($scriptRun, $result->stdout, $result->stderr);

        $metadata = [
            ...$redaction['metadata'],
            ...$result->metadata,
            'image' => $manifest->image,
            'network_mode' => $manifest->networkMode,
        ];

        if ($this->timedOut($result)) {
            $metadata['timed_out'] = true;
        }

        $metadata = $this->withLogArtifacts($scriptRun, $redaction['stdout'], $redaction['stderr'], $metadata);
        $metadata = $this->withOutputArtifacts($scriptRun, $result->artifacts, $metadata);

        $scriptRun->forceFill([
            'state' => $this->state($result),
            'stdout' => $redaction['stdout'],
            'stderr' => $redaction['stderr'],
            'exit_code' => $result->exitCode,
            'finished_at' => now(),
            'metadata' => $metadata,
        ])->save();

        return $scriptRun->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withLogArtifacts(ScriptRun $scriptRun, string $stdout, string $stderr, array $metadata): array
    {
        $stdoutArtifact = $this->artifacts->write($scriptRun, 'stdout', $stdout);

        if ($stdoutArtifact instanceof Artifact) {
            $metadata['stdout_artifact_id'] = $stdoutArtifact->id;
        }

        $stderrArtifact = $this->artifacts->write($scriptRun, 'stderr', $stderr);

        if ($stderrArtifact instanceof Artifact) {
            $metadata['stderr_artifact_id'] = $stderrArtifact->id;
        }

        return $metadata;
    }

    /**
     * @param  list<ContainerOutputArtifact>  $outputArtifacts
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withOutputArtifacts(ScriptRun $scriptRun, array $outputArtifacts, array $metadata): array
    {
        $artifactIds = [];
        $redactions = $this->redactions($this->metadata($scriptRun));

        foreach ($outputArtifacts as $outputArtifact) {
            if ($this->shouldRedactOutputArtifact($outputArtifact)) {
                $redacted = $this->redactContent($outputArtifact->content, $redactions);
                $outputArtifact = $outputArtifact->withContent($redacted['content']);

                if ($redacted['count'] > 0) {
                    $metadata['redaction_count'] = $this->redactionCount($metadata['redaction_count'] ?? null) + $redacted['count'];
                }
            }

            $artifact = $this->artifacts->writeOutput($scriptRun, $outputArtifact);

            if ($artifact instanceof Artifact) {
                $artifactIds[] = $artifact->id;
            }
        }

        if ($artifactIds !== []) {
            $metadata['output_artifact_ids'] = $artifactIds;
        }

        return $metadata;
    }

    private function shouldRedactOutputArtifact(ContainerOutputArtifact $outputArtifact): bool
    {
        $contentType = strtolower($outputArtifact->contentType);

        return $outputArtifact->kind !== 'script_screenshot'
            && (
                $outputArtifact->kind === 'script_log'
                || $outputArtifact->kind === 'script_serial'
                || str_starts_with($contentType, 'text/')
                || str_contains($contentType, 'json')
                || str_contains($contentType, 'xml')
            );
    }

    /**
     * @param  list<string>  $redactions
     * @return array{content: string, count: int}
     */
    private function redactContent(string $content, array $redactions): array
    {
        $count = 0;

        foreach ($redactions as $secret) {
            $count += substr_count($content, $secret);
            $content = str_replace($secret, '[redacted]', $content);
        }

        return [
            'content' => $content,
            'count' => $count,
        ];
    }

    private function state(ContainerRunResult $result): string
    {
        if ($this->timedOut($result)) {
            return 'timed_out';
        }

        return $result->exitCode === 0 ? 'succeeded' : 'failed';
    }

    private function timedOut(ContainerRunResult $result): bool
    {
        return $result->timedOut || ($result->metadata['timed_out'] ?? false) === true;
    }

    /**
     * @return array{stdout: string, stderr: string, metadata: array<string, mixed>}
     */
    private function redaction(ScriptRun $scriptRun, string $stdout, string $stderr): array
    {
        $metadata = $this->metadata($scriptRun);
        $redactions = $this->redactions($metadata);
        unset($metadata['redactions']);

        $count = 0;

        foreach ($redactions as $secret) {
            $count += substr_count($stdout, $secret);
            $count += substr_count($stderr, $secret);
            $stdout = str_replace($secret, '[redacted]', $stdout);
            $stderr = str_replace($secret, '[redacted]', $stderr);
        }

        if ($count > 0) {
            $metadata['redaction_count'] = $this->redactionCount($metadata['redaction_count'] ?? null) + $count;
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(ScriptRun $scriptRun): array
    {
        return is_array($scriptRun->metadata) ? $scriptRun->metadata : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function redactions(array $metadata): array
    {
        $redactions = $metadata['redactions'] ?? [];

        if (! is_array($redactions)) {
            return [];
        }

        return array_values(array_filter(
            $redactions,
            static fn (mixed $secret): bool => is_string($secret) && $secret !== '',
        ));
    }

    private function redactionCount(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
