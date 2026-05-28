<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;
use ZipArchive;

final readonly class BackupArchiveWriter
{
    /**
     * @param  array<string, string>  $files
     * @param  array<string, mixed>  $metadata
     */
    public function write(string $archivePath, array $files, array $metadata = []): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException(sprintf('Unable to create RackLab backup archive [%s].', $archivePath));
        }

        foreach ($files as $path => $contents) {
            if (! $zip->addFromString($path, $contents)) {
                $zip->close();

                throw new RuntimeException(sprintf('Unable to write backup file [%s].', $path));
            }
        }

        $manifest = $this->manifest($files, $metadata);
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (! $zip->addFromString('manifest.json', $manifestJson."\n")) {
            $zip->close();

            throw new RuntimeException('Unable to write backup manifest.');
        }

        if (! $zip->close()) {
            throw new RuntimeException(sprintf('Unable to close RackLab backup archive [%s].', $archivePath));
        }
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function manifest(array $files, array $metadata): array
    {
        $manifestFiles = [];

        foreach ($files as $path => $contents) {
            $manifestFiles[] = [
                'path' => $path,
                'sha256' => hash('sha256', $contents),
                'bytes' => strlen($contents),
            ];
        }

        return [
            'schema_version' => BackupArchiveVerifier::SCHEMA_VERSION,
            'created_at' => gmdate('c'),
            'metadata' => $metadata,
            'files' => $manifestFiles,
        ];
    }
}
