<?php

declare(strict_types=1);

namespace App\Scripts;

use App\Jobs\RunAnsiblePlaybook;
use App\Jobs\RunConsoleScript;
use App\Jobs\RunScriptContainer;
use App\Jobs\RunUserScript;
use JsonException;

final readonly class ScriptRunnerRegistry
{
    /**
     * @return list<string>
     */
    public static function runnerKinds(): array
    {
        return [
            'advanced_code',
            'user_script',
            'cloudinit',
            'openqa',
            'console_script',
            'network',
            'ansible',
        ];
    }

    public static function createPermission(string $runnerKind): string
    {
        return match ($runnerKind) {
            'cloudinit' => 'script.cloudinit.create',
            'openqa', 'console_script' => 'script.openqa.create',
            'network', 'ansible' => 'script.network.create',
            default => 'script.advanced_code.create',
        };
    }

    /**
     * @return class-string<RunScriptContainer>|null
     */
    public static function jobClassFor(string $runnerKind): ?string
    {
        return match ($runnerKind) {
            'advanced_code', 'user_script' => RunUserScript::class,
            'network', 'ansible' => RunAnsiblePlaybook::class,
            'openqa', 'console_script' => RunConsoleScript::class,
            default => null,
        };
    }

    /**
     * @param  list<string>  $command
     *
     * @throws JsonException
     */
    public static function executableHash(array $command, string $source): string
    {
        return hash('sha256', json_encode(
            ['command' => $command, 'source' => $source],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @return list<string>
     */
    public static function normalizeCommand(mixed $command): array
    {
        if (! is_array($command)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $part): string => (string) $part,
            array_filter($command, is_scalar(...)),
        ));
    }
}
