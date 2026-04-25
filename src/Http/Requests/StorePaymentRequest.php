<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'provider' => ['sometimes', 'string', Rule::in(['mpesa', 'paystack', 'flutterwave', 'pesapal', 'manual'])],
            'payment_method' => ['sometimes', 'string', Rule::in(['mpesa', 'card', 'bank', 'mobile_money', 'manual'])],
            'subscription_id' => ['nullable', 'integer'],
            'invoice_id' => ['nullable', 'integer'],
            'options' => ['sometimes', 'array'],
            'options.phone' => ['sometimes', 'string', 'max:20'],
            'options.reference' => ['sometimes', 'string', 'max:255'],
            'options.notes' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
