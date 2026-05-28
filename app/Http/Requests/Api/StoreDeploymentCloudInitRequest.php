<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDeploymentCloudInitRequest extends FormRequest
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
            'script_version_id' => ['required', 'string'],
            'project_ssh_key_ids' => ['nullable', 'array'],
            'project_ssh_key_ids.*' => ['required', 'string'],
            'deployment_resource_id' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'script_version_id' => [
                'description' => 'Approved cloud-init ScriptVersion to render for this deployment.',
                'example' => '01HZSCRIPTVERSION0000000000',
            ],
            'project_ssh_key_ids' => [
                'description' => 'Project SSH keys to inject into the rendered cloud-init payload.',
                'example' => ['01HZSSHKEY0000000000000000'],
            ],
            'project_ssh_key_ids.*' => [
                'description' => 'Project SSH key id selected for injection.',
                'example' => '01HZSSHKEY0000000000000000',
            ],
            'deployment_resource_id' => [
                'description' => 'Optional VM resource receiving this cloud-init payload.',
                'example' => '01HZDEPLOYMENTRESOURCE0000',
            ],
        ];
    }
}
