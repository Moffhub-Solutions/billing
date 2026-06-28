<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Moffhub\Billing\Enums\PaymentMethod;
use Moffhub\Billing\PaymentManager;

class StorePaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Restrict the provider to those offered at checkout, falling back to
        // every registered driver when no curated list is configured.
        $manager = app(PaymentManager::class);
        $enabled = $manager->getEnabledProviders();
        $allowedProviders = $enabled !== [] ? $enabled : $manager->getAvailableProviders();

        $allowedMethods = array_map(fn (PaymentMethod $m): string => $m->value, PaymentMethod::cases());

        return [
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'provider' => ['sometimes', 'string', Rule::in($allowedProviders)],
            'payment_method' => ['sometimes', 'string', Rule::in($allowedMethods)],
            'subscription_id' => ['nullable', 'integer'],
            'invoice_id' => ['nullable', 'integer'],
            'options' => ['sometimes', 'array'],
            'options.phone' => ['sometimes', 'string', 'max:20'],
            'options.reference' => ['sometimes', 'string', 'max:255'],
            'options.notes' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
