<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Database\Factories\PromotionCodeFactory;

/**
 * @property int $id
 * @property int $coupon_id
 * @property string $code
 * @property bool $is_active
 * @property bool $first_time_transaction
 * @property int|null $minimum_amount
 * @property int|null $max_redemptions
 * @property int $times_redeemed
 * @property Carbon|null $expires_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Coupon $coupon
 * @property-read Collection<int, CouponRedemption> $redemptions
 *
 * @method static Builder<self> active()
 */
class PromotionCode extends Model
{
    /** @use HasFactory<PromotionCodeFactory> */
    use HasFactory;

    protected static function newFactory(): PromotionCodeFactory
    {
        return PromotionCodeFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'first_time_transaction' => 'boolean',
            'minimum_amount' => 'integer',
            'max_redemptions' => 'integer',
            'times_redeemed' => 'integer',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.promotion_codes', 'billing_promotion_codes');

        return is_string($value) ? $value : 'billing_promotion_codes';
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * Check if this promotion code can be redeemed.
     */
    public function isRedeemable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_redemptions !== null && $this->times_redeemed >= $this->max_redemptions) {
            return false;
        }

        // Also check the underlying coupon
        return $this->coupon->isRedeemable();
    }

    /**
     * Validate restrictions against a billable and amount.
     *
     * @return array<int, string>
     */
    public function validateRestrictions(Model $billable, int $amount): array
    {
        $errors = [];

        if ($this->first_time_transaction) {
            $hasPayments = $billable->morphMany(Payment::class, 'billable')
                ->where('status', 'completed')
                ->exists();

            if ($hasPayments) {
                $errors[] = 'This promotion code is only valid for first-time customers.';
            }
        }

        if ($this->minimum_amount !== null && $amount < $this->minimum_amount) {
            $currency = config('billing.currency', 'KES');
            $currencyString = is_string($currency) ? $currency : 'KES';
            $formatted = $currencyString.' '.number_format($this->minimum_amount / 100, 2);
            $errors[] = "Minimum purchase amount of {$formatted} required.";
        }

        return $errors;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Find a promotion code by its code string.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', strtoupper($code))->first();
    }
}
