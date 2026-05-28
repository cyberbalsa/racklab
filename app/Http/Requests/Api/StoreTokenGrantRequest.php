<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTokenGrantRequest extends FormRequest
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
            'project_id' => ['required', 'string'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Human-readable token name shown in the token list and audit metadata.',
                'example' => 'CI deploy token',
            ],
            'project_id' => [
                'description' => 'Project scope for the issued Track-B token.',
                'example' => '01HZPROJECT0000000000000000',
            ],
            'abilities' => [
                'description' => 'Delegated permission strings requested for the token.',
                'example' => ['project.read', 'deployment.read'],
            ],
            'abilities.*' => [
                'description' => 'Single delegated permission string.',
                'example' => 'deployment.read',
            ],
            'expires_at' => [
                'description' => 'Optional expiration timestamp. Omit for a long-lived PAT.',
                'example' => '2026-06-30T23:59:59Z',
            ],
        ];
    }
}
