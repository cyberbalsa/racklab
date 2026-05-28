<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDeploymentRequest extends FormRequest
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
            'stack_definition_id' => ['nullable', 'string'],
            'catalog_version_id' => ['nullable', 'string'],
            'operation' => ['nullable', 'string', Rule::in(['deploy', 'add_vm'])],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160'],
            'lease_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'simulate_failure' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'project_id' => [
                'description' => 'Project that receives the deployment.',
                'example' => '01HZPROJECT0000000000000000',
            ],
            'stack_definition_id' => [
                'description' => 'Project-local Stack definition to deploy. Omit when deploying a catalog version.',
                'example' => '01HZSTACK000000000000000000',
            ],
            'catalog_version_id' => [
                'description' => 'Published catalog version to deploy. Omit when deploying a project-local Stack.',
                'example' => '01HZCATVER0000000000000000',
            ],
            'operation' => [
                'description' => 'Initial operation requested for the deployment.',
                'example' => 'deploy',
            ],
            'idempotency_key' => [
                'description' => 'Client supplied key that makes duplicate deployment requests return the original operation.',
                'example' => 'deploy-intro-linux-001',
            ],
            'lease_duration_minutes' => [
                'description' => 'Optional requested deployment lease duration in minutes. If omitted, RackLab applies the most restrictive lease-duration quota policy when one exists.',
                'example' => 120,
            ],
            'simulate_failure' => [
                'description' => 'Testing-only flag used by the fake provider to force a failed provider task.',
                'example' => false,
            ],
        ];
    }
}
