<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeatureRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:255', Rule::unique(billing_table('features', 'billing_features'), 'slug')],
            'name' => ['required', 'string', 'max:255'],
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
