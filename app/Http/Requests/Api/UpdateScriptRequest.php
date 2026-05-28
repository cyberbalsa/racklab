<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateScriptRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'command' => ['sometimes', 'array', 'min:1'],
            'command.*' => ['required', 'string', 'max:240'],
            'source' => ['sometimes', 'string'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Updated human-readable script name.',
                'example' => 'Install course tooling v2',
            ],
            'command' => [
                'description' => 'Replacement argument vector. Changing this creates a new executable ScriptVersion and invalidates approvals.',
                'example' => ['ansible-playbook', 'site.yml'],
            ],
            'command.*' => [
                'description' => 'Single argument in the replacement command vector.',
                'example' => 'site.yml',
            ],
            'source' => [
                'description' => 'Replacement source. Changing this creates a new executable ScriptVersion and invalidates approvals.',
                'example' => "- hosts: all\n  tasks: []\n",
            ],
            'metadata' => [
                'description' => 'Updated caller metadata. Metadata-only edits preserve active approvals.',
                'example' => ['reviewed_by' => 'instructor'],
            ],
        ];
    }
}
