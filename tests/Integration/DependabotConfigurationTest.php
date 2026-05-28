<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('declares the four ecosystems', function (): void {
    $config = Yaml::parseFile(base_path('.github/dependabot.yml'));
    expect($config['version'])->toBe(2);

    $ecosystems = array_column($config['updates'], 'package-ecosystem');
    expect($ecosystems)->toEqualCanonicalizing(['composer', 'npm', 'github-actions', 'docker']);
});

it('uses conventional-commit prefixes', function (): void {
    $config = Yaml::parseFile(base_path('.github/dependabot.yml'));
    foreach ($config['updates'] as $update) {
        expect($update['commit-message']['prefix'])->toBeIn(['build(deps)', 'ci(deps)']);
    }
});
