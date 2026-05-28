<?php

declare(strict_types=1);

use App\Models\NetworkVpnEndpoint;
use App\Models\NetworkVpnEndpointBinding;
use App\Models\VpnClientProfile;
use App\Models\VpnSession;

it('exposes the documented endpoint lifecycle states', function (): void {
    expect(NetworkVpnEndpoint::STATE_PENDING)->toBe('pending')
        ->and(NetworkVpnEndpoint::STATE_RUNNING)->toBe('running')
        ->and(NetworkVpnEndpoint::STATE_STOPPED)->toBe('stopped')
        ->and(NetworkVpnEndpoint::STATE_RELEASED)->toBe('released')
        ->and(NetworkVpnEndpoint::STATE_FAILED)->toBe('failed');
});

it('exposes the documented endpoint binding states', function (): void {
    expect(NetworkVpnEndpointBinding::STATE_PENDING)->toBe('pending')
        ->and(NetworkVpnEndpointBinding::STATE_ACTIVE)->toBe('active')
        ->and(NetworkVpnEndpointBinding::STATE_RELEASED)->toBe('released')
        ->and(NetworkVpnEndpointBinding::STATE_FAILED)->toBe('failed');
});

it('exposes the documented client profile states', function (): void {
    expect(VpnClientProfile::STATE_ACTIVE)->toBe('active')
        ->and(VpnClientProfile::STATE_REVOKED)->toBe('revoked')
        ->and(VpnClientProfile::STATE_EXPIRED)->toBe('expired');
});

it('exposes the documented session states', function (): void {
    expect(VpnSession::STATE_ACTIVE)->toBe('active')
        ->and(VpnSession::STATE_CLOSED)->toBe('closed');
});
