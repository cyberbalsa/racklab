<?php

declare(strict_types=1);

use App\Stacks\StackPackageExporter;

function readPackageEntry(string $bytes, string $entry): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'racklab-stack-pkg');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive;
    $zip->open($tmp);

    $contents = (string) $zip->getFromName($entry);
    $zip->close();
    unlink($tmp);

    return $contents;
}

it('exports a stack as an OVA-style package with a portable manifest and checksums', function (): void {
    $package = (new StackPackageExporter)->export(
        name: 'Two-tier Lab',
        slug: 'two-tier-lab',
        definition: [
            'version' => 1,
            'provider' => 'proxmox',
            'components' => [
                [
                    'key' => 'vm',
                    'kind' => 'vm',
                    'proxmox' => [
                        'node' => 'csr-hv-07',
                        'template_vmid' => 9000,
                        'target_vmid' => 142,
                        'storage' => 'local-lvm',
                    ],
                    'networks' => [
                        ['key' => 'eth0', 'offering_slug' => 'student-lan'],
                    ],
                ],
            ],
        ],
    );

    expect($package->filename)->toBe('two-tier-lab.racklab-stack.zip');

    $stackJson = json_decode(readPackageEntry($package->bytes, 'racklab-stack.json'), true, flags: JSON_THROW_ON_ERROR);

    // Format + identity.
    expect($stackJson['format_version'])->toBe(StackPackageExporter::FORMAT_VERSION)
        ->and($stackJson['stack']['name'])->toBe('Two-tier Lab')
        ->and($stackJson['stack']['slug'])->toBe('two-tier-lab');

    $component = $stackJson['stack']['components'][0];

    // Logical network references are portable rebinding hints (kept).
    expect($component['networks'][0]['offering_slug'])->toBe('student-lan');

    // Provider-specific instance identifiers are stripped to import hints,
    // never exported as concrete IDs.
    expect($component)->not->toHaveKey('proxmox')
        ->and($stackJson['import_hints']['network_offerings'])->toContain('student-lan');

    // Manifest checksums cover the stack file.
    $manifest = json_decode(readPackageEntry($package->bytes, 'manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $entry = collect($manifest['files'])->firstWhere('path', 'racklab-stack.json');

    expect($entry['sha256'])->toBe(hash('sha256', readPackageEntry($package->bytes, 'racklab-stack.json')));
});

it('never includes tenant, project, or database identifiers in the package', function (): void {
    $package = (new StackPackageExporter)->export(
        name: 'Secret Stack',
        slug: 'secret-stack',
        definition: [
            'provider' => 'fake',
            'components' => [['key' => 'vm', 'kind' => 'vm', 'networks' => []]],
        ],
    );

    $stackJson = readPackageEntry($package->bytes, 'racklab-stack.json');

    expect($stackJson)->not->toContain('tenant_id')
        ->and($stackJson)->not->toContain('project_id');
});
