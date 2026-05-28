<?php

declare(strict_types=1);

use App\Backup\BackupArchiveReader;
use App\Backup\BackupArchiveWriter;

it('reads and verifies a RackLab backup archive', function (): void {
    $archivePath = sys_get_temp_dir().'/racklab-backup-reader-'.bin2hex(random_bytes(6)).'.zip';

    try {
        (new BackupArchiveWriter)->write($archivePath, [
            'database.sqlite' => 'sqlite bytes',
        ], [
            'database_driver' => 'sqlite',
        ]);

        $archive = (new BackupArchiveReader)->readVerified($archivePath);

        expect($archive->manifest['metadata']['database_driver'])->toBe('sqlite')
            ->and($archive->files['database.sqlite'])->toBe('sqlite bytes');
    } finally {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
    }
});

it('rejects a tampered RackLab backup archive while reading', function (): void {
    $archivePath = sys_get_temp_dir().'/racklab-backup-reader-'.bin2hex(random_bytes(6)).'.zip';

    try {
        (new BackupArchiveWriter)->write($archivePath, [
            'database.sqlite' => 'sqlite bytes',
        ]);

        $zip = new ZipArchive;
        expect($zip->open($archivePath))->toBeTrue();
        $zip->deleteName('database.sqlite');
        $zip->addFromString('database.sqlite', 'tampered');
        $zip->close();

        (new BackupArchiveReader)->readVerified($archivePath);
    } finally {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
    }
})->throws(RuntimeException::class, 'RackLab backup archive verification failed: sha256_mismatch [database.sqlite].');
