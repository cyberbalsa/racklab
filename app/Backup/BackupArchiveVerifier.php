<?php

declare(strict_types=1);

namespace App\Backup;

final readonly class BackupArchiveVerifier
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     */
    public function verify(array $manifest, array $files): BackupArchiveVerificationResult
    {
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return BackupArchiveVerificationResult::invalid('unsupported_schema_version');
        }

        $expectedHashes = $this->expectedHashes($manifest['files'] ?? null);

        if ($expectedHashes === null) {
            return BackupArchiveVerificationResult::invalid('invalid_manifest');
        }

        foreach ($expectedHashes as $path => $expectedSha256) {
            if (! array_key_exists($path, $files)) {
                return BackupArchiveVerificationResult::invalid('missing_file', $path);
            }

            if (! hash_equals($expectedSha256, hash('sha256', $files[$path]))) {
                return BackupArchiveVerificationResult::invalid('sha256_mismatch', $path);
            }
        }

        foreach (array_keys($files) as $path) {
            if (! array_key_exists($path, $expectedHashes)) {
                return BackupArchiveVerificationResult::invalid('unexpected_file', $path);
            }
        }

        return BackupArchiveVerificationResult::valid();
    }

    /**
     * @return array<string, string>|null
     */
    private function expectedHashes(mixed $manifestFiles): ?array
    {
        if (! is_array($manifestFiles)) {
            return null;
        }

        $expectedHashes = [];

        foreach ($manifestFiles as $manifestFile) {
            if (! is_array($manifestFile)) {
                return null;
            }

            $path = $manifestFile['path'] ?? null;
            $sha256 = $manifestFile['sha256'] ?? null;

            if (! is_string($path) || $path === '' || ! is_string($sha256) || $sha256 === '') {
                return null;
            }

            $expectedHashes[$path] = $sha256;
        }

        return $expectedHashes;
    }
}
