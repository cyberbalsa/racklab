<?php

declare(strict_types=1);

use App\Backup\BackupArchiveVerifier;
use App\Backup\BackupArchiveWriter;

it('writes a zip backup archive with a manifest and hashed files', function (): void {
    $archivePath = sys_get_temp_dir().'/racklab-backup-writer-'.bin2hex(random_bytes(6)).'.zip';

    try {
        (new BackupArchiveWriter)->write($archivePath, [
            'database.sqlite' => 'sqlite bytes',
            'redis.rdb' => 'redis bytes',
        ], [
            'database_driver' => 'sqlite',
        ]);

        $zip = new ZipArchive;
        expect($zip->open($archivePath))->toBeTrue()
            ->and($zip->getFromName('database.sqlite'))->toBe('sqlite bytes')
            ->and($zip->getFromName('redis.rdb'))->toBe('redis bytes');

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $files = [
            'database.sqlite' => (string) $zip->getFromName('database.sqlite'),
            'redis.rdb' => (string) $zip->getFromName('redis.rdb'),
        ];

        $zip->close();

        expect($manifest['schema_version'])->toBe(BackupArchiveVerifier::SCHEMA_VERSION)
            ->and($manifest['metadata']['database_driver'])->toBe('sqlite')
            ->and($manifest['files'])->toHaveCount(2)
            ->and((new BackupArchiveVerifier)->verify($manifest, $files)->valid)->toBeTrue();
    } finally {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
    }
});
