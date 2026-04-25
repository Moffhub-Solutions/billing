<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Database\Factories\CouponFactory;
use Moffhub\Billing\Enums\CouponDuration;
use Moffhub\Billing\Enums\DiscountType;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property DiscountType $discount_type
 * @property int $discount_value
 * @property string|null $currency
 * @property CouponDuration|null $duration
 * @property int|null $duration_in_months
 * @property int|null $max_redemptions
 * @property int $times_redeemed
 * @property Carbon|null $redeem_by
 * @property bool $is_active
 * @property array<int, string>|null $applies_to_plans
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, PromotionCode> $promotionCodes
 * @property-read Collection<int, CouponRedemption> $redemptions
 *
 * @method static Builder<self> active()
 * @method static Builder<self> redeemable()
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    protected static function newFactory(): CouponFactory
    {
        return CouponFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'integer',
            'duration' => CouponDuration::class,
            'duration_in_months' => 'integer',
            'max_redemptions' => 'integer',
            'times_redeemed' => 'integer',
            'redeem_by' => 'datetime',
            'is_active' => 'boolean',
            'applies_to_plans' => 'array',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.coupons', 'billing_coupons');

        return is_string($value) ? $value : 'billing_coupons';
    }

    /**
     * @return HasMany<PromotionCode, $this>
     */
    public function promotionCodes(): HasMany
    {
        return $this->hasMany(PromotionCode::class);
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * Check if this coupon can still be redeemed.
     */
    public function isRedeemable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->redeem_by !== null && $this->redeem_by->isPast()) {
            return false;
        }

        if ($this->max_redemptions !== null && $this->times_redeemed >= $this->max_redemptions) {
            return false;
        }

        return true;
    }

    /**
     * Check if this coupon applies to a specific plan.
     */
    public function appliesToPlan(string $planSlug): bool
    {
        // null or empty means applies to all plans
        if (empty($this->applies_to_plans)) {
            return true;
        }

        return in_array($planSlug, $this->applies_to_plans, true);
    }

    /**
     * Calculate the discount amount for a given price.
     */
    public function calculateDiscount(int $amount): int
    {
        return match ($this->discount_type) {
            DiscountType::PERCENT => (int) round($amount * ($this->discount_value / 100)),
            DiscountType::FIXED => min($this->discount_value, $amount),
        };
    }

    /**
     * Get the human-readable discount description.
     */
    public function discountDescription(): string
    {
        $currency = config('billing.currency', 'KES');
        $currencyString = is_string($currency) ? $currency : 'KES';

        return match ($this->discount_type) {
            DiscountType::PERCENT => "{$this->discount_value}% off",
            DiscountType::FIXED => $currencyString.' '.number_format($this->discount_value / 100, 2).' off',
        };
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRedeemable(Builder $query): Builder
    {
        return $query->active()
            ->where(function ($q): void {
                $q->whereNull('redeem_by')->orWhere('redeem_by', '>', now());
            })
            ->where(function ($q): void {
                $q->whereNull('max_redemptions')
                    ->orWhereColumn('times_redeemed', '<', 'max_redemptions');
            });
    }
}
