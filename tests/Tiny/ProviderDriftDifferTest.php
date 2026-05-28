<?php

declare(strict_types=1);

use App\Networking\ProviderDriftDiffer;

it('reports stable provider drift paths for nested state differences', function (): void {
    $differences = (new ProviderDriftDiffer)->diff(
        expected: [
            'state' => 'active',
            'rules' => [
                [
                    'direction' => 'ingress',
                    'protocol' => 'tcp',
                    'port_min' => 22,
                ],
            ],
        ],
        observed: [
            'rules' => [
                [
                    'direction' => 'ingress',
                    'protocol' => 'tcp',
                    'port_min' => 2222,
                ],
                [
                    'direction' => 'egress',
                    'protocol' => 'any',
                    'port_min' => null,
                ],
            ],
            'state' => 'active',
        ],
    );

    expect(array_column($differences, 'path'))->toBe([
        'rules.0.port_min',
        'rules.1',
    ])
        ->and($differences[0]['expected'])->toBe(22)
        ->and($differences[0]['observed'])->toBe(2222);
});
