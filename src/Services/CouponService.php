<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Moffhub\Billing\Exceptions\CouponException;
use Moffhub\Billing\Models\Coupon;
use Moffhub\Billing\Models\CouponRedemption;
use Moffhub\Billing\Models\PromotionCode;
use Moffhub\Billing\Models\Subscription;

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
        if ($subscriptionId !== null && method_exists($billable, 'subscriptions')) {
            $subscription = $billable->subscriptions()->find($subscriptionId);

            if ($subscription instanceof Subscription && ! $coupon->appliesToPlan($subscription->plan->slug)) {
                throw CouponException::notApplicableToPlan($subscription->plan->name);
            }
        }

        $discountAmount = $coupon->calculateDiscount($amount);
        $finalAmount = max(0, $amount - $discountAmount);

        // Record + count under row locks, re-checking redeemability inside the
        // transaction so two concurrent redemptions can't both pass an exhausted
        // max_redemptions cap (check-then-act race on a single-use code).
        return DB::transaction(function () use ($coupon, $promoCode, $billable, $amount, $subscriptionId, $invoiceId, $discountAmount, $finalAmount, $code): array {
            $lockedCoupon = Coupon::query()->lockForUpdate()->find($coupon->id);
            $lockedPromo = PromotionCode::query()->lockForUpdate()->find($promoCode->id);

            if (! $lockedCoupon instanceof Coupon || ! $lockedCoupon->isRedeemable()
                || ! $lockedPromo instanceof PromotionCode || ! $lockedPromo->isRedeemable()) {
                throw CouponException::expired($code);
            }

            CouponRedemption::create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'coupon_id' => $lockedCoupon->id,
                'promotion_code_id' => $lockedPromo->id,
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoiceId,
                'original_amount' => $amount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'redeemed_at' => now(),
            ]);

            $lockedCoupon->increment('times_redeemed');
            $lockedPromo->increment('times_redeemed');

            return [
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'coupon' => $lockedCoupon,
                'promotion_code' => $lockedPromo,
            ];
        });
    }

    /**
     * Apply a coupon directly (without promotion code).
     *
     * @return array{discount_amount: int, final_amount: int, coupon: Coupon, promotion_code: PromotionCode|null}
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

        return DB::transaction(function () use ($coupon, $billable, $amount, $subscriptionId, $invoiceId, $discountAmount, $finalAmount): array {
            $lockedCoupon = Coupon::query()->lockForUpdate()->find($coupon->id);

            if (! $lockedCoupon instanceof Coupon || ! $lockedCoupon->isRedeemable()) {
                throw CouponException::expired($coupon->name);
            }

            CouponRedemption::create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'coupon_id' => $lockedCoupon->id,
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoiceId,
                'original_amount' => $amount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'redeemed_at' => now(),
            ]);

            $lockedCoupon->increment('times_redeemed');

            return [
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'coupon' => $lockedCoupon,
                'promotion_code' => null,
            ];
        });
    }

    /**
     * Preview what a code would discount (without redeeming).
     *
     * @return array<string, mixed>
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
