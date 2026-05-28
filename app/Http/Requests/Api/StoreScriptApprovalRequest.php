<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreScriptApprovalRequest extends FormRequest
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
            'scope_type' => [
                'required',
                'string',
                Rule::in(['deployment', 'project', 'course', 'catalog_version', 'catalog_script']),
            ],
            'scope_id' => ['nullable', 'string', 'required_unless:scope_type,catalog_script'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'scope_type' => [
                'description' => 'Scope at which this script version is approved.',
                'example' => 'project',
            ],
            'scope_id' => [
                'description' => 'Scope id required for every approval scope except catalog_script.',
                'example' => '01HZPROJECT0000000000000000',
            ],
            'metadata' => [
                'description' => 'Optional approval metadata retained for audit and review.',
                'example' => ['ticket' => 'LAB-42'],
            ],
        ];
    }
}
