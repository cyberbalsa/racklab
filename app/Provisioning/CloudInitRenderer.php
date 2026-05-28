<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\ProjectSshKey;

final readonly class CloudInitRenderer
{
    /**
     * @param  list<ProjectSshKey>  $sshKeys
     */
    public function render(string $source, array $sshKeys, string $phoneHomeUrl): string
    {
        $base = rtrim($source);

        if ($base === '') {
            $base = '#cloud-config';
        }

        if (! str_starts_with($base, '#cloud-config')) {
            $base = "#cloud-config\n".$base;
        }

        $lines = [$base, '', 'ssh_authorized_keys:'];

        if ($sshKeys === []) {
            $lines[] = '  []';
        }

        foreach ($sshKeys as $sshKey) {
            $lines[] = '  - '.json_encode($sshKey->public_key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $lines[] = '';
        $lines[] = 'racklab:';
        $lines[] = '  host_key_phone_home_url: '.json_encode($phoneHomeUrl, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $lines[] = '  project_ssh_keys:';

        if ($sshKeys === []) {
            $lines[] = '    []';
        }

        foreach ($sshKeys as $sshKey) {
            $lines[] = '    - id: '.json_encode($sshKey->getKey(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $lines[] = '      fingerprint: '.json_encode($sshKey->fingerprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines)."\n";
    }

    public function redactPhoneHomeToken(string $rendered, string $plainToken): string
    {
        return str_replace($plainToken, '[redacted-phone-home-token]', $rendered);
    }
}
