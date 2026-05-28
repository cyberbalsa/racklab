<?php

declare(strict_types=1);

use App\Backup\BackupArchiveVerifier;

it('accepts a backup manifest when every file hash matches', function (): void {
    $result = (new BackupArchiveVerifier)->verify([
        'schema_version' => 1,
        'files' => [
            [
                'path' => 'database.sql',
                'sha256' => hash('sha256', 'sql dump'),
            ],
            [
                'path' => 'redis.rdb',
                'sha256' => hash('sha256', 'redis snapshot'),
            ],
        ],
    ], [
        'database.sql' => 'sql dump',
        'redis.rdb' => 'redis snapshot',
    ]);

    expect($result->valid)->toBeTrue()
        ->and($result->reason)->toBeNull()
        ->and($result->path)->toBeNull();
});

it('rejects backup manifests with an unsupported schema version', function (): void {
    $result = (new BackupArchiveVerifier)->verify([
        'schema_version' => 99,
        'files' => [],
    ], []);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('unsupported_schema_version');
});

it('rejects backup archives with missing files', function (): void {
    $result = (new BackupArchiveVerifier)->verify([
        'schema_version' => 1,
        'files' => [
            [
                'path' => 'database.sql',
                'sha256' => hash('sha256', 'sql dump'),
            ],
        ],
    ], []);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('missing_file')
        ->and($result->path)->toBe('database.sql');
});

it('rejects backup archives with tampered file contents', function (): void {
    $result = (new BackupArchiveVerifier)->verify([
        'schema_version' => 1,
        'files' => [
            [
                'path' => 'database.sql',
                'sha256' => hash('sha256', 'sql dump'),
            ],
        ],
    ], [
        'database.sql' => 'tampered',
    ]);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('sha256_mismatch')
        ->and($result->path)->toBe('database.sql');
});

it('rejects backup archives with unmanifested files', function (): void {
    $result = (new BackupArchiveVerifier)->verify([
        'schema_version' => 1,
        'files' => [],
    ], [
        'unexpected.txt' => 'content',
    ]);

    expect($result->valid)->toBeFalse()
        ->and($result->reason)->toBe('unexpected_file')
        ->and($result->path)->toBe('unexpected.txt');
});
