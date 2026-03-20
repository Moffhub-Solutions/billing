<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CouponRedemption extends Model
{
    use HasFactory;

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
        return config('billing.tables.coupon_redemptions', 'billing_coupon_redemptions');
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function promotionCode(): BelongsTo
    {
        return $this->belongsTo(PromotionCode::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
