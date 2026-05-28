<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreHostKeyPhoneHomeRequest extends FormRequest
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
            'keys' => ['required', 'array', 'min:1'],
            'keys.*.public_key' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'keys' => [
                'description' => 'Host public keys reported by the guest during first boot.',
                'example' => [
                    ['public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIRackLabHostKey guest@example'],
                ],
            ],
            'keys.*.public_key' => [
                'description' => 'OpenSSH-formatted host public key.',
                'example' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIRackLabHostKey guest@example',
            ],
        ];
    }
}
