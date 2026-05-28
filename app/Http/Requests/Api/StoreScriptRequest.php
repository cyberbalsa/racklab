<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Scripts\ScriptRunnerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreScriptRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'runner_kind' => ['required', 'string', Rule::in(ScriptRunnerRegistry::runnerKinds())],
            'command' => ['required', 'array', 'min:1'],
            'command.*' => ['required', 'string', 'max:240'],
            'source' => ['required', 'string'],
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
                'description' => 'Project that owns the script.',
                'example' => '01HZPROJECT0000000000000000',
            ],
            'name' => [
                'description' => 'Human-readable script name shown to approvers and runners.',
                'example' => 'Install course tooling',
            ],
            'runner_kind' => [
                'description' => 'Runner substrate for this script version.',
                'example' => 'ansible',
            ],
            'command' => [
                'description' => 'Argument vector executed inside the selected runner container.',
                'example' => ['ansible-playbook', 'site.yml'],
            ],
            'source' => [
                'description' => 'Executable script source or structured runner definition.',
                'example' => "- hosts: all\n  tasks: []\n",
            ],
            'metadata' => [
                'description' => 'Caller metadata retained with the script definition.',
                'example' => ['course' => 'intro-linux'],
            ],
        ];
    }
}
