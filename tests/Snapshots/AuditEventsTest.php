<?php

declare(strict_types=1);

it('matches the committed audit event type snapshot', function (): void {
    $snapshot = json_decode(
        (string) file_get_contents(__DIR__.'/audit-events.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(auditEventTypesInProductionCode())->toBe($snapshot);
});

it('has contract coverage for every implemented audit event type', function (): void {
    $contractSource = auditEventSnapshotSource(__DIR__.'/../Contract');

    foreach (auditEventTypesInProductionCode() as $eventType) {
        expect($contractSource)->toContain($eventType);
    }
});

/**
 * @return list<string>
 */
function auditEventTypesInProductionCode(): array
{
    $types = [];

    foreach (auditEventSnapshotPhpFiles(__DIR__.'/../../app') as $file) {
        $source = (string) file_get_contents($file);

        foreach (auditEventTypesAssignedLiterally($source) as $eventType) {
            $types[$eventType] = true;
        }

        if (str_contains($source, "'event_type' => \$eventType") || str_contains($source, '"event_type" => $eventType')) {
            foreach (auditEventTypesReferencedInDynamicEmitter($source) as $eventType) {
                $types[$eventType] = true;
            }
        }
    }

    $eventTypes = array_keys($types);
    sort($eventTypes);

    return $eventTypes;
}

/**
 * @return list<string>
 */
function auditEventTypesAssignedLiterally(string $source): array
{
    preg_match_all('/[\'"]event_type[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);

    return array_values(array_filter(
        $matches[1],
        static fn (string $eventType): bool => str_contains($eventType, '.'),
    ));
}

/**
 * @return list<string>
 */
function auditEventTypesReferencedInDynamicEmitter(string $source): array
{
    preg_match_all('/[\'"]((?:auth|script)\.[a-z_]+)[\'"]/', $source, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * @return list<string>
 */
function auditEventSnapshotPhpFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function auditEventSnapshotSource(string $directory): string
{
    $source = '';

    foreach (auditEventSnapshotPhpFiles($directory) as $file) {
        $source .= file_get_contents($file)."\n";
    }

    return $source;
}
