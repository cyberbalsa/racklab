<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreVpnClientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'network_vpn_endpoint_id' => ['required', 'string'],
            'user_id' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'network_vpn_endpoint_id' => [
                'description' => 'VPN endpoint the profile attaches to.',
                'example' => '01HZVPNENDPOINT00000000',
            ],
            'user_id' => [
                'description' => 'Owner of the profile. Defaults to the authenticated user. Admin/support/instructor may specify another tenant member; download remains owner-only.',
                'example' => 42,
            ],
            'expires_at' => [
                'description' => 'Optional expiry timestamp (RFC 3339). Profiles past expiry are not active and cannot be downloaded.',
                'example' => '2026-12-31T23:59:59Z',
            ],
        ];
    }
}
