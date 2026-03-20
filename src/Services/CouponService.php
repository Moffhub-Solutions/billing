<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Moffhub\Billing\Exceptions\CouponException;
use Moffhub\Billing\Models\Coupon;
use Moffhub\Billing\Models\CouponRedemption;
use Moffhub\Billing\Models\PromotionCode;

class CouponService
{
    /**
     * Apply a promotion code to an amount.
     *
     * @return array{discount_amount: int, final_amount: int, coupon: Coupon, promotion_code: PromotionCode|null}
     */
    public function applyCode(
        string $code,
        Model $billable,
        int $amount,
        ?int $subscriptionId = null,
        ?int $invoiceId = null,
    ): array {
        $promoCode = PromotionCode::findByCode($code);

        if (! $promoCode instanceof PromotionCode) {
            throw CouponException::invalidCode($code);
        }

        if (! $promoCode->isRedeemable()) {
            throw CouponException::expired($code);
        }

        // Validate restrictions
        $errors = $promoCode->validateRestrictions($billable, $amount);

        if ($errors !== []) {
            throw CouponException::restrictionsFailed($errors);
        }

        $coupon = $promoCode->coupon;

        // Check plan restrictions
        if ($subscriptionId !== null) {
            $subscription = $billable->subscriptions()->find($subscriptionId);

            if ($subscription && ! $coupon->appliesToPlan($subscription->plan->slug)) {
                throw CouponException::notApplicableToPlan($subscription->plan->name);
            }
        }

        $discountAmount = $coupon->calculateDiscount($amount);
        $finalAmount = max(0, $amount - $discountAmount);

        // Record the redemption
        CouponRedemption::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'coupon_id' => $coupon->id,
            'promotion_code_id' => $promoCode->id,
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'original_amount' => $amount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'redeemed_at' => now(),
        ]);

        // Increment redemption counters
        $coupon->increment('times_redeemed');
        $promoCode->increment('times_redeemed');

        return [
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'coupon' => $coupon,
            'promotion_code' => $promoCode,
        ];
    }

    /**
     * Apply a coupon directly (without promotion code).
     */
    public function applyCoupon(
        Coupon $coupon,
        Model $billable,
        int $amount,
        ?int $subscriptionId = null,
        ?int $invoiceId = null,
    ): array {
        if (! $coupon->isRedeemable()) {
            throw CouponException::expired($coupon->name);
        }

        $discountAmount = $coupon->calculateDiscount($amount);
        $finalAmount = max(0, $amount - $discountAmount);

        CouponRedemption::create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
            'coupon_id' => $coupon->id,
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'original_amount' => $amount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'redeemed_at' => now(),
        ]);

        $coupon->increment('times_redeemed');

        return [
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'coupon' => $coupon,
            'promotion_code' => null,
        ];
    }

    /**
     * Preview what a code would discount (without redeeming).
     */
    public function preview(string $code, Model $billable, int $amount): array
    {
        $promoCode = PromotionCode::findByCode($code);

        if (! $promoCode instanceof PromotionCode || ! $promoCode->isRedeemable()) {
            return ['valid' => false, 'reason' => 'Invalid or expired code.'];
        }

        $errors = $promoCode->validateRestrictions($billable, $amount);

        if ($errors !== []) {
            return ['valid' => false, 'reason' => implode(' ', $errors)];
        }

        $coupon = $promoCode->coupon;
        $discountAmount = $coupon->calculateDiscount($amount);

        return [
            'valid' => true,
            'code' => $promoCode->code,
            'description' => $coupon->discountDescription(),
            'discount_amount' => $discountAmount,
            'final_amount' => max(0, $amount - $discountAmount),
        ];
    }
}
