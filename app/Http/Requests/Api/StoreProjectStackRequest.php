<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectStackRequest extends FormRequest
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
            'definition' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Project-local Stack name.',
                'example' => 'Linux workstation',
            ],
            'definition' => [
                'description' => 'Stack component definition stored as versioned JSON.',
                'example' => [
                    'provider' => 'fake',
                    'components' => [
                        ['key' => 'vm1', 'kind' => 'vm'],
                    ],
                ],
            ],
        ];
    }
}
