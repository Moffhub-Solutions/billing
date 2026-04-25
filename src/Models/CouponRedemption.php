<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Database\Factories\CouponRedemptionFactory;

/**
 * @property int $id
 * @property string $billable_type
 * @property int $billable_id
 * @property int $coupon_id
 * @property int|null $promotion_code_id
 * @property int|null $subscription_id
 * @property int|null $invoice_id
 * @property int $discount_amount
 * @property int $original_amount
 * @property int $final_amount
 * @property Carbon $redeemed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model $billable
 * @property-read Coupon $coupon
 * @property-read PromotionCode|null $promotionCode
 * @property-read Subscription|null $subscription
 * @property-read Invoice|null $invoice
 */
class CouponRedemption extends Model
{
    /** @use HasFactory<CouponRedemptionFactory> */
    use HasFactory;

    protected static function newFactory(): CouponRedemptionFactory
    {
        return CouponRedemptionFactory::new();
    }

    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'discount_amount' => 'integer',
            'original_amount' => 'integer',
            'final_amount' => 'integer',
            'redeemed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.coupon_redemptions', 'billing_coupon_redemptions');

        return is_string($value) ? $value : 'billing_coupon_redemptions';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return BelongsTo<PromotionCode, $this>
     */
    public function promotionCode(): BelongsTo
    {
        return $this->belongsTo(PromotionCode::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
