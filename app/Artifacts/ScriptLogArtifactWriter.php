<?php

declare(strict_types=1);

namespace App\Artifacts;

use App\Models\Artifact;
use App\Models\ArtifactReference;
use App\Models\ScriptRun;
use App\Runtime\ContainerOutputArtifact;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ScriptLogArtifactWriter
{
    /**
     * @var list<string>
     */
    private const array OUTPUT_KINDS = [
        'script_log',
        'script_screenshot',
        'script_serial',
    ];

    public function write(ScriptRun $scriptRun, string $stream, string $content): ?Artifact
    {
        if ($content === '') {
            return null;
        }

        if (! in_array($stream, ['stdout', 'stderr'], true)) {
            throw new InvalidArgumentException('Script log artifacts only support stdout and stderr streams.');
        }

        $path = sprintf(
            'artifacts/%s/script-runs/%s/%s.log',
            $scriptRun->tenant_id,
            $scriptRun->id,
            $stream,
        );

        Storage::disk('local')->put($path, $content);

        /** @var Artifact $artifact */
        $artifact = Artifact::query()->create([
            'tenant_id' => $scriptRun->tenant_id,
            'kind' => 'script_log',
            'content_type' => 'text/plain; charset=utf-8',
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'quarantined' => true,
            ...$this->ownerScope($scriptRun),
            'rbac_visibility' => 'actor_only',
            'metadata' => [
                'stream' => $stream,
                'script_run_id' => $scriptRun->id,
            ],
        ]);

        ArtifactReference::query()->create([
            'tenant_id' => $scriptRun->tenant_id,
            'artifact_id' => $artifact->id,
            'reference_type' => ScriptRun::class,
            'reference_id' => $scriptRun->id,
            'purpose' => 'script_'.$stream,
        ]);

        return $artifact;
    }

    public function writeOutput(ScriptRun $scriptRun, ContainerOutputArtifact $outputArtifact): ?Artifact
    {
        if ($outputArtifact->content === '') {
            return null;
        }

        if (! in_array($outputArtifact->kind, self::OUTPUT_KINDS, true)) {
            throw new InvalidArgumentException('Unsupported script output artifact kind.');
        }

        if (trim($outputArtifact->purpose) === '') {
            throw new InvalidArgumentException('Script output artifacts require a non-empty purpose.');
        }

        $path = sprintf(
            'artifacts/%s/script-runs/%s/%s-%s',
            $scriptRun->tenant_id,
            $scriptRun->id,
            Str::ulid()->toBase32(),
            $this->safeFilename($outputArtifact->filename),
        );

        Storage::disk('local')->put($path, $outputArtifact->content);

        /** @var Artifact $artifact */
        $artifact = Artifact::query()->create([
            'tenant_id' => $scriptRun->tenant_id,
            'kind' => $outputArtifact->kind,
            'content_type' => $outputArtifact->contentType,
            'size_bytes' => strlen($outputArtifact->content),
            'sha256' => hash('sha256', $outputArtifact->content),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'quarantined' => true,
            ...$this->ownerScope($scriptRun),
            'rbac_visibility' => 'actor_only',
            'metadata' => [
                ...$outputArtifact->metadata,
                'filename' => $outputArtifact->filename,
                'script_run_id' => $scriptRun->id,
            ],
        ]);

        ArtifactReference::query()->create([
            'tenant_id' => $scriptRun->tenant_id,
            'artifact_id' => $artifact->id,
            'reference_type' => ScriptRun::class,
            'reference_id' => $scriptRun->id,
            'purpose' => $outputArtifact->purpose,
        ]);

        return $artifact;
    }

    private function safeFilename(string $filename): string
    {
        $basename = basename($filename);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $basename);

        if (! is_string($safe) || trim($safe) === '') {
            return 'artifact.bin';
        }

        return $safe;
    }

    /**
     * @return array{owner_scope_type: string|null, owner_scope_id: string|null}
     */
    private function ownerScope(ScriptRun $scriptRun): array
    {
        if ($scriptRun->project_id !== null) {
            return [
                'owner_scope_type' => 'project',
                'owner_scope_id' => $scriptRun->project_id,
            ];
        }

        if ($scriptRun->actor_user_id !== null) {
            return [
                'owner_scope_type' => 'user',
                'owner_scope_id' => (string) $scriptRun->actor_user_id,
            ];
        }

        return [
            'owner_scope_type' => null,
            'owner_scope_id' => null,
        ];
    }
}
