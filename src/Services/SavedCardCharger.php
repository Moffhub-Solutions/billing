<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\PaymentToken;
use Moffhub\Billing\Models\Subscription;

/**
 * Saved cards: keep the reusable card Paystack returns after a card payment,
 * and supply it when a renewal or a dunning retry has to charge without the
 * customer present.
 */
class SavedCardCharger
{
    /**
     * The options a renewal charge needs for this provider. A provider that
     * charges from a saved card (Paystack) needs the customer's email and the
     * stored authorization, so `null` means there is no usable saved card and
     * nothing should be sent. Other providers need nothing extra here.
     *
     * `$attempt` is part of the reference, so a retried job cannot charge the
     * same period twice (Paystack refuses a reference it has already seen)
     * while a later dunning attempt is still allowed.
     *
     * @return array<string, mixed>|null
     */
    public function options(Subscription $subscription, string $provider, string $description, string $attempt = 'renew'): ?array
    {
        $options = [
            'subscription_id' => $subscription->id,
            'description' => $description,
        ];

        if ($provider !== 'paystack') {
            return $options;
        }

        $token = $this->usableToken($subscription, $provider);

        if ($token === null || $token->email === null || $token->token === null) {
            return null;
        }

        $period = $subscription->current_period_end?->timestamp ?? 0;

        return [
            ...$options,
            'email' => $token->email,
            'authorization_code' => $token->token,
            'reference' => 'bill-'.$subscription->ulid.'-'.$period.'-'.$attempt,
        ];
    }

    public function usableToken(Subscription $subscription, string $provider): ?PaymentToken
    {
        $tokens = PaymentToken::query()
            ->where('billable_type', $subscription->billable_type)
            ->where('billable_id', $subscription->billable_id)
            ->where('provider', $provider)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return $tokens->first(fn (PaymentToken $token): bool => $token->isUsable());
    }

    /**
     * Keep the card from a completed Paystack payment, once per card, so later
     * renewals can charge it. Only a reusable card is kept, and only for a
     * payment this app started (it names who is paying).
     *
     * @param  array<string, mixed>  $event  PaystackProvider::parseWebhook output
     */
    public function captureFromPaystack(array $event): ?PaymentToken
    {
        $meta = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        $auth = is_array($meta['authorization'] ?? null) ? $meta['authorization'] : [];
        $reference = $event['provider_payment_id'] ?? null;

        if (($auth['reusable'] ?? false) !== true || ($auth['channel'] ?? $meta['channel'] ?? null) !== 'card') {
            return null;
        }

        $code = is_string($auth['authorization_code'] ?? null) ? $auth['authorization_code'] : null;

        if ($code === null || ! is_string($reference) || $reference === '') {
            return null;
        }

        $payment = Payment::query()->where('provider_payment_id', $reference)->first();

        if ($payment === null) {
            return null;
        }

        $customer = is_array($meta['customer'] ?? null) ? $meta['customer'] : [];
        $email = is_string($customer['email'] ?? null) ? strtolower($customer['email']) : null;
        $signature = is_string($auth['signature'] ?? null) ? $auth['signature'] : null;

        $existing = PaymentToken::query()
            ->where('billable_type', $payment->billable_type)
            ->where('billable_id', $payment->billable_id)
            ->where('provider', 'paystack')
            ->get();

        // The same card again (by Paystack's signature, or the same code)
        // refreshes the stored one instead of piling up duplicates.
        $token = $existing->first(fn (PaymentToken $stored): bool => $stored->token === $code
            || ($signature !== null && ($stored->metadata['signature'] ?? null) === $signature));

        $isNew = $token === null;
        $token ??= new PaymentToken([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => $payment->billable_type,
            'billable_id' => $payment->billable_id,
            'provider' => 'paystack',
            'token_type' => 'card',
            'is_default' => $existing->where('is_default', true)->isEmpty(),
        ]);

        $month = is_string($auth['exp_month'] ?? null) ? $auth['exp_month'] : null;
        $year = is_string($auth['exp_year'] ?? null) ? $auth['exp_year'] : null;

        $token->token = $code;
        $token->email = $email ?? $token->email;
        $token->is_reusable = true;
        $token->last_four = is_string($auth['last4'] ?? null) ? $auth['last4'] : $token->last_four;
        $token->card_brand = is_string($auth['brand'] ?? null) ? $auth['brand'] : $token->card_brand;
        $token->card_exp_month = $month ?? $token->card_exp_month;
        $token->card_exp_year = $year ?? $token->card_exp_year;
        $token->expires_at = $this->expiry($month, $year) ?? $token->expires_at;
        $token->metadata = [...($token->metadata ?? []), 'signature' => $signature];

        if ($isNew || $token->isDirty()) {
            $token->save();
        }

        return $token;
    }

    /**
     * A card is good through the end of its expiry month.
     */
    protected function expiry(?string $month, ?string $year): ?Carbon
    {
        if ($month === null || $year === null || ! ctype_digit($month) || ! ctype_digit($year)) {
            return null;
        }

        try {
            return Carbon::create((int) $year, (int) $month, 1)->endOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }
}
