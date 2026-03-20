<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeatureRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['boolean', 'metered', 'consumable'])],
            'is_addon' => ['sometimes', 'boolean'],
            'addon_price' => ['nullable', 'integer', 'min:0'],
            'addon_billing_cycle' => ['nullable', 'string', Rule::in(['monthly', 'quarterly', 'annual'])],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
