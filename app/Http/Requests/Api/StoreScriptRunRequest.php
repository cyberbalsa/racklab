<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreScriptRunRequest extends FormRequest
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
            'deployment_id' => ['nullable', 'string'],
            'deployment_resource_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'deployment_id' => [
                'description' => 'Deployment context for the script run, when the run targets a deployed Stack.',
                'example' => '01HZDEPLOYMENT000000000000',
            ],
            'deployment_resource_id' => [
                'description' => 'Specific deployment resource targeted by the script run.',
                'example' => '01HZDEPLOYMENTRESOURCE0000',
            ],
            'metadata' => [
                'description' => 'Run metadata, including one-shot redaction values consumed by the script worker.',
                'example' => ['redactions' => ['secret-value']],
            ],
        ];
    }
}
