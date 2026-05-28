<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\NetworkVpnEndpoint;
use App\Models\NetworkVpnEndpointBinding;
use App\Models\User;

/**
 * Default S4 generator: produces opaque placeholder cert + key bytes and a
 * minimal OpenVPN client config wrapping the binding's public IP + UDP port.
 *
 * Suitable for the in-memory + fake-VPN provider in S4/S5. M5c S6 ships an
 * OpenSSL-backed generator that returns real X.509 material signed against
 * the pool's CA.
 *
 * The material is treated as opaque downstream — the issuer encrypts at rest
 * via Laravel Crypt, audits each download, and revokes by setting
 * VpnClientProfile state without ever inspecting cert content.
 */
final readonly class PlaceholderVpnClientProfileGenerator implements VpnClientProfileGenerator
{
    public function generate(
        NetworkVpnEndpoint $endpoint,
        NetworkVpnEndpointBinding $binding,
        User $owner,
        string $commonName,
    ): VpnClientProfileMaterial {
        $cert = "-----BEGIN CERTIFICATE-----\n".
            base64_encode('placeholder client certificate for '.$commonName).
            "\n-----END CERTIFICATE-----\n";

        $key = "-----BEGIN PRIVATE KEY-----\n".
            base64_encode('placeholder private key for '.$commonName).
            "\n-----END PRIVATE KEY-----\n";

        $config = $this->renderConfig($endpoint, $binding, $cert, $key);

        return new VpnClientProfileMaterial(
            config: $config,
            certificatePem: $cert,
            privateKeyPem: $key,
        );
    }

    private function renderConfig(
        NetworkVpnEndpoint $endpoint,
        NetworkVpnEndpointBinding $binding,
        string $cert,
        string $key,
    ): string {
        return implode("\n", [
            'client',
            'dev tap',
            'proto udp',
            sprintf('remote %s %d', $binding->public_ip, $binding->udp_port),
            'nobind',
            'persist-key',
            'persist-tun',
            'remote-cert-tls server',
            sprintf('; capability %s', $endpoint->capability),
            sprintf('; endpoint %s', $endpoint->resourceId()),
            '<cert>',
            trim($cert),
            '</cert>',
            '<key>',
            trim($key),
            '</key>',
            '',
        ]);
    }
}
