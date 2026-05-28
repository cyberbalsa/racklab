<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectSshKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'public_key' => ['required', 'string', 'max:4096'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Human-readable SSH key label within the Project.',
                'example' => 'Alice laptop',
            ],
            'public_key' => [
                'description' => 'OpenSSH-formatted user public key injected into cloud-init.',
                'example' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIRackLabUserKey alice@example',
            ],
            'metadata' => [
                'description' => 'Optional caller metadata retained with the Project SSH key.',
                'example' => ['source' => 'self-service'],
            ],
        ];
    }
}
