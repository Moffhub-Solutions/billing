<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_price' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'billing_cycle' => ['sometimes', 'string', Rule::in(['monthly', 'quarterly', 'annual'])],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', 'max:255'],
            'limits' => ['sometimes', 'array'],
            'limits.*' => ['integer', 'min:0'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
