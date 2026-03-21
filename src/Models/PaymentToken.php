<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Moffhub\Billing\Casts\EncryptedString;
use Moffhub\Billing\Database\Factories\PaymentTokenFactory;

class PaymentToken extends Model
{
    /** @use HasFactory<PaymentTokenFactory> */
    use HasFactory;

    protected static function newFactory(): PaymentTokenFactory
    {
        return PaymentTokenFactory::new();
    }

    protected $guarded = ['id'];

    /**
     * PII fields encrypted at rest when billing.security.encrypt_at_rest is enabled.
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'token' => EncryptedString::class,
            'phone' => EncryptedString::class,
            'email' => EncryptedString::class,
            'card_exp_month' => EncryptedString::class,
            'card_exp_year' => EncryptedString::class,
            'is_default' => 'boolean',
            'is_reusable' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Fields that should never appear in serialized output.
     */
    protected $hidden = ['token'];

    #[\Override]
    public function getTable(): string
    {
        return config('billing.tables.payment_tokens', 'billing_payment_tokens');
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check if this token can be used for a charge.
     */
    public function isUsable(): bool
    {
        if (! $this->is_reusable) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Mark this token as used now.
     */
    public function markUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Get a display label (e.g., "M-Pesa ...5678" or "Visa ...4242").
     */
    public function displayLabel(): string
    {
        $last4 = $this->last_four ?? '****';

        return match ($this->provider) {
            'mpesa' => "M-Pesa ...{$last4}",
            'paystack' => "{$this->card_brand} ...{$last4}",
            'flutterwave' => "{$this->card_brand} ...{$last4}",
            default => "{$this->token_type} ...{$last4}",
        };
    }

    /**
     * Scope to default payment token.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to usable tokens.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_reusable', true)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
