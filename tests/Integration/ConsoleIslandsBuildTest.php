<?php

declare(strict_types=1);

/**
 * Regression guard for codex M4 sub-slice 4 P2: standalone Vite entries are
 * subject to tree-shaking. The island entry files register their mount
 * functions on `window.RackLab.console` so the produced bundles always carry
 * the public seam. If a future refactor drops the side-effect registration,
 * Vite emits ~0-byte bundles and consumers silently get an empty module.
 */
it('emits a non-trivial novnc-viewer bundle from the Vite build manifest', function (): void {
    skipUnlessViteBuildExists();
    $bundlePath = assertConsoleIslandBundle('resources/js/islands/novnc-viewer.ts');

    expect(filesize($bundlePath))->toBeGreaterThan(200);

    $bundle = (string) file_get_contents($bundlePath);
    expect($bundle)->toContain('mountNoVncViewer')
        ->toContain('window.RackLab');
});

it('emits a non-trivial xterm-console bundle from the Vite build manifest', function (): void {
    skipUnlessViteBuildExists();
    $bundlePath = assertConsoleIslandBundle('resources/js/islands/xterm-console.ts');

    expect(filesize($bundlePath))->toBeGreaterThan(200);

    $bundle = (string) file_get_contents($bundlePath);
    expect($bundle)->toContain('mountXtermConsole')
        ->toContain('window.RackLab');
});

function skipUnlessViteBuildExists(): void
{
    $manifest = consoleIslandsRepoRoot().'/public/build/manifest.json';

    if (! file_exists($manifest)) {
        test()->markTestSkipped('Vite build artifacts not present; run `npm run build` first.');
    }
}

function consoleIslandsRepoRoot(): string
{
    return dirname(__DIR__, 2);
}

function assertConsoleIslandBundle(string $entry): string
{
    $manifestPath = consoleIslandsRepoRoot().'/public/build/manifest.json';
    $manifest = json_decode(
        (string) file_get_contents($manifestPath),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest)->toBeArray()->toHaveKey($entry);
    expect($manifest[$entry])->toBeArray()->toHaveKey('file');

    $bundlePath = consoleIslandsRepoRoot().'/public/build/'.$manifest[$entry]['file'];
    expect(file_exists($bundlePath))->toBeTrue();

    return $bundlePath;
}
