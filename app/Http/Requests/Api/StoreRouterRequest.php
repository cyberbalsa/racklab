<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRouterRequest extends FormRequest
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
            'project_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'network_ids' => ['required', 'array', 'min:2'],
            'network_ids.*' => ['required', 'string', 'distinct'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'project_id' => [
                'description' => 'Project that owns the managed router.',
                'example' => '01HZPROJECT000000000000000',
            ],
            'name' => [
                'description' => 'Human-readable router name.',
                'example' => 'Lab Router',
            ],
            'slug' => [
                'description' => 'Project-unique router slug.',
                'example' => 'lab-router',
            ],
            'network_ids' => [
                'description' => 'At least two project network ids to attach to this router.',
                'example' => ['01HZNETWORKLEFT000000000', '01HZNETWORKRIGHT00000000'],
            ],
            'metadata' => [
                'description' => 'Optional router metadata.',
                'example' => ['purpose' => 'inter-subnet lab routing'],
            ],
        ];
    }
}
