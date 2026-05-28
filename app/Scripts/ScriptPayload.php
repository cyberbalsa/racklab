<?php

declare(strict_types=1);

namespace App\Scripts;

use App\Models\Artifact;
use App\Models\ArtifactReference;
use App\Models\Script;
use App\Models\ScriptApproval;
use App\Models\ScriptRun;
use App\Models\ScriptVersion;

final readonly class ScriptPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Script $script): array
    {
        /** @var ScriptVersion|null $version */
        $version = $script->currentVersion;

        return [
            'id' => $script->getKey(),
            'tenant_id' => $script->tenant_id,
            'project_id' => $script->project_id,
            'name' => $script->name,
            'slug' => $script->slug,
            'runner_kind' => $script->runner_kind,
            'state' => $script->state,
            'metadata' => $script->metadata ?? [],
            'current_version' => $version instanceof ScriptVersion ? self::version($version) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(ScriptVersion $version): array
    {
        return [
            'id' => $version->getKey(),
            'script_id' => $version->script_id,
            'version_number' => $version->version_number,
            'command' => $version->command,
            'source' => $version->source,
            'executable_hash' => $version->executable_hash,
            'metadata' => $version->metadata ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function approval(ScriptApproval $approval): array
    {
        return [
            'id' => $approval->getKey(),
            'script_id' => $approval->script_id,
            'script_version_id' => $approval->script_version_id,
            'scope_type' => $approval->scope_type,
            'scope_id' => $approval->scope_id,
            'state' => $approval->state,
            'invalidated_at' => $approval->invalidated_at?->toJSON(),
            'invalidation_reason' => $approval->invalidation_reason,
            'metadata' => $approval->metadata ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(ScriptRun $run): array
    {
        return [
            'id' => $run->getKey(),
            'tenant_id' => $run->tenant_id,
            'project_id' => $run->project_id,
            'script_id' => $run->script_id,
            'script_version_id' => $run->script_version_id,
            'deployment_id' => $run->deployment_id,
            'deployment_resource_id' => $run->deployment_resource_id,
            'runner_kind' => $run->runner_kind,
            'state' => $run->state,
            'command' => $run->command,
            'exit_code' => $run->exit_code,
            'metadata' => $run->metadata ?? [],
            'artifacts' => self::runArtifacts($run),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function runArtifacts(ScriptRun $run): array
    {
        $references = ArtifactReference::query()
            ->with('artifact')
            ->where('reference_type', ScriptRun::class)
            ->where('reference_id', $run->getKey())
            ->get();
        $artifacts = [];

        foreach ($references as $reference) {
            $artifact = $reference->artifact;

            if (! $artifact instanceof Artifact) {
                continue;
            }

            $artifacts[] = [
                'id' => $artifact->getKey(),
                'kind' => $artifact->kind,
                'content_type' => $artifact->content_type,
                'size_bytes' => $artifact->size_bytes,
                'sha256' => $artifact->sha256,
                'purpose' => $reference->purpose,
                'quarantined' => $artifact->quarantined,
            ];
        }

        return $artifacts;
    }
}
