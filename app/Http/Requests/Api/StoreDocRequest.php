<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDocRequest extends FormRequest
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
        // Codex M8 S2 P1 #5: `exists:projects,id` would silently confirm
        // that a foreign-tenant project id exists (different 422 vs 404),
        // leaking cross-tenant existence. The tenant-scoped lookup in
        // DocStoreController handles validation correctly — 404 either
        // way.
        return [
            'project_id' => ['required', 'string'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'markdown' => ['required', 'string', 'max:524288'],
            'editor_message' => ['nullable', 'string', 'max:280'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'project_id' => [
                'description' => 'Project that scopes the document.',
                'example' => '01HZPROJECT0000000000000000',
            ],
            'title' => [
                'description' => 'Human-readable title shown in navigation and search.',
                'example' => 'Lab 1: Building the network',
            ],
            'markdown' => [
                'description' => 'Markdown source. Stored verbatim and re-rendered to HTML on every save.',
                'example' => "# Lab 1\n\nIntroductory exercise.",
            ],
            'editor_message' => [
                'description' => 'Optional commit-message style note attached to the initial version.',
                'example' => 'initial draft',
            ],
        ];
    }
}
