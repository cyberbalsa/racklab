<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDeploymentOperationRequest extends FormRequest
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
            'kind' => ['required', 'string', Rule::in(['add_vm', 'remove_vm', 'rebuild_vm', 'rebuild_stack', 'release', 'power_on', 'power_off'])],
            'deployment_resource_id' => ['nullable', 'string', 'required_if:kind,remove_vm,rebuild_vm'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160'],
            'simulate_failure' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'kind' => [
                'description' => 'Deployment operation to enqueue.',
                'example' => 'release',
            ],
            'deployment_resource_id' => [
                'description' => 'Target resource for resource-scoped operations such as remove or rebuild.',
                'example' => '01HZDEPLOYMENTRESOURCE0000',
            ],
            'idempotency_key' => [
                'description' => 'Client supplied key that makes duplicate operation requests return the original operation.',
                'example' => 'release-intro-linux-001',
            ],
            'simulate_failure' => [
                'description' => 'Testing-only flag used by the fake provider to force a failed provider task.',
                'example' => false,
            ],
        ];
    }
}
