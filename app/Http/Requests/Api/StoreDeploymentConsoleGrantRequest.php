<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Console\ConsoleKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDeploymentConsoleGrantRequest extends FormRequest
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
            'console_kind' => ['required', 'string', Rule::in(ConsoleKind::supportedValues())],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'console_kind' => [
                'description' => 'Console kind to open. Use `vnc` for KVM graphical console; use `terminal` for LXC or KVM serial console.',
                'example' => 'vnc',
            ],
        ];
    }
}
