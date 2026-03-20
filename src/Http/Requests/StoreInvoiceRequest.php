<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'subscription_id' => ['nullable', 'integer'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'due_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['sometimes', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
            'items.*.feature_slug' => ['nullable', 'string', 'max:255'],
            'items.*.period_start' => ['nullable', 'date'],
            'items.*.period_end' => ['nullable', 'date', 'after_or_equal:items.*.period_start'],
        ];
    }
}
