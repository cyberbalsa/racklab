<?php

declare(strict_types=1);

namespace Racklab\NetworkVpnaasOpenvpn;

final readonly class Manifest
{
    public function slug(): string
    {
        return 'racklab/network-vpnaas-openvpn';
    }

    public function name(): string
    {
        return 'RackLab OpenVPN VPNaaS';
    }

    public function description(): string
    {
        return 'Provides the OpenVPN VPNaaS capability (network:vpnaas:openvpn:v1).';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return ['network:vpnaas:openvpn:v1'];
    }
}
