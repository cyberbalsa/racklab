<?php

declare(strict_types=1);

namespace App\Stacks;

use RuntimeException;
use ZipArchive;

/**
 * Exports a StackDefinition as a RackLab Stack Package (PRD §08): an OVA-style
 * zip archive containing `racklab-stack.json` (format version, portable
 * StackDefinition, provider requirements, import hints) plus a checksum
 * manifest.
 *
 * Portability rules:
 * - Logical network references (`offering_slug`) are kept as rebinding hints —
 *   they map to site-specific offerings at import time.
 * - Provider-specific instance identifiers (Proxmox node/vmid/storage, etc.)
 *   are stripped; only the kind/role survives, so a package never carries a
 *   source cluster's concrete IDs.
 * - Tenant, project, and database identifiers are never written.
 * - Secrets are never exported (only their references, as rebinding hints).
 */
final readonly class StackPackageExporter
{
    public const int FORMAT_VERSION = 1;

    /**
     * @param  array<string, mixed>  $definition
     */
    public function export(string $name, string $slug, array $definition): StackPackage
    {
        $components = [];
        $offeringHints = [];

        foreach ($this->components($definition) as $component) {
            [$portable, $hints] = $this->portableComponent($component);
            $components[] = $portable;
            $offeringHints = [...$offeringHints, ...$hints];
        }

        $stackJson = json_encode([
            'format_version' => self::FORMAT_VERSION,
            'stack' => [
                'name' => $name,
                'slug' => $slug,
                'provider_requirement' => is_string($definition['provider'] ?? null) ? $definition['provider'] : null,
                'components' => $components,
            ],
            'import_hints' => [
                'network_offerings' => array_values(array_unique($offeringHints)),
                'note' => 'Network offerings and any secret references must be rebound to local resources at import time.',
            ],
            'source' => [
                'exported_by' => 'racklab',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";

        return new StackPackage(
            filename: $slug.'.racklab-stack.zip',
            bytes: $this->zip(['racklab-stack.json' => $stackJson]),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array<array-key, mixed>>
     */
    private function components(array $definition): array
    {
        $components = $definition['components'] ?? [];

        if (! is_array($components)) {
            return [];
        }

        $normalized = [];

        foreach ($components as $component) {
            if (is_array($component)) {
                $normalized[] = $component;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $component
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function portableComponent(array $component): array
    {
        $portable = [
            'key' => is_string($component['key'] ?? null) ? $component['key'] : 'vm',
            'kind' => is_string($component['kind'] ?? null) ? $component['kind'] : 'vm',
        ];

        if (isset($component['resources']) && is_array($component['resources'])) {
            $portable['resources'] = $component['resources'];
        }

        $offeringHints = [];
        $networks = [];

        if (isset($component['networks']) && is_array($component['networks'])) {
            foreach ($component['networks'] as $network) {
                if (! is_array($network)) {
                    continue;
                }

                $slug = is_string($network['offering_slug'] ?? null) ? $network['offering_slug'] : null;
                $networks[] = [
                    'key' => is_string($network['key'] ?? null) ? $network['key'] : 'eth0',
                    'offering_slug' => $slug,
                ];

                if ($slug !== null) {
                    $offeringHints[] = $slug;
                }
            }
        }

        $portable['networks'] = $networks;

        // Provider-specific instance identifiers (e.g. component['proxmox'])
        // are intentionally not copied into the portable component.
        return [$portable, $offeringHints];
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'racklab-stack-export');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the stack package.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the stack package archive.');
        }

        $manifestFiles = [];

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
            $manifestFiles[] = [
                'path' => $name,
                'sha256' => hash('sha256', $contents),
                'bytes' => strlen($contents),
            ];
        }

        $zip->addFromString('manifest.json', json_encode([
            'format_version' => self::FORMAT_VERSION,
            'files' => $manifestFiles,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

        $zip->close();

        $bytes = file_get_contents($path);
        unlink($path);

        if ($bytes === false) {
            throw new RuntimeException('Unable to read the built stack package.');
        }

        return $bytes;
    }
}
