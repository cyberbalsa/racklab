<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDocRequest extends FormRequest
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
            'title' => [
                'description' => 'Human-readable title shown in navigation and search.',
                'example' => 'Lab 1: Building the network',
            ],
            'markdown' => [
                'description' => 'Markdown source for the new version. Append-only: the prior version is retained.',
                'example' => "# Lab 1\n\nRevised wording.",
            ],
            'editor_message' => [
                'description' => 'Optional commit-message style note describing this revision.',
                'example' => 'clarify section 2',
            ],
        ];
    }
}
