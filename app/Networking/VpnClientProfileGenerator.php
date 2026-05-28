<?php

declare(strict_types=1);

namespace App\Networking;

use App\Models\NetworkVpnEndpoint;
use App\Models\NetworkVpnEndpointBinding;
use App\Models\User;

/**
 * Generates the per-user material that goes into a VPN client profile.
 *
 * The S4 default implementation produces opaque placeholder bytes (no real
 * X.509). M5c S6 swaps in an OpenSSL-backed generator that produces a real
 * client certificate signed by the pool's CA, with the cert + private key
 * material returned in PEM format.
 *
 * The contract is intentionally narrow: the generator hands the issuer a
 * ProfileMaterial triple, and the issuer takes care of encrypting it and
 * rendering an .ovpn payload. Tests can substitute a deterministic
 * generator to avoid OpenSSL overhead.
 */
interface VpnClientProfileGenerator
{
    public function generate(
        NetworkVpnEndpoint $endpoint,
        NetworkVpnEndpointBinding $binding,
        User $owner,
        string $commonName,
    ): VpnClientProfileMaterial;
}
