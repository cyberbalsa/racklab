<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;
use ZipArchive;

final readonly class BackupArchiveReader
{
    public function __construct(private BackupArchiveVerifier $verifier = new BackupArchiveVerifier) {}

    public function readVerified(string $archivePath): BackupArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException(sprintf('Unable to open RackLab backup archive [%s].', $archivePath));
        }

        $manifestJson = $zip->getFromName('manifest.json');

        if (! is_string($manifestJson)) {
            $zip->close();

            throw new RuntimeException('RackLab backup archive is missing manifest.json.');
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        $files = $this->files($zip);
        $zip->close();

        $result = $this->verifier->verify($manifest, $files);

        if (! $result->valid) {
            $path = $result->path === null ? '' : sprintf(' [%s]', $result->path);

            throw new RuntimeException(sprintf('RackLab backup archive verification failed: %s%s.', $result->reason, $path));
        }

        return new BackupArchive($manifest, $files);
    }

    /**
     * @return array<string, string>
     */
    private function files(ZipArchive $zip): array
    {
        $files = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if (! is_array($stat)) {
                continue;
            }

            if (! is_string($stat['name'] ?? null)) {
                continue;
            }

            $name = $stat['name'];

            if ($name === 'manifest.json') {
                continue;
            }

            $contents = $zip->getFromName($name);

            if (is_string($contents)) {
                $files[$name] = $contents;
            }
        }

        return $files;
    }
}
