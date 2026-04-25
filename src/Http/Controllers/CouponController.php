<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Moffhub\Billing\Exceptions\CouponException;
use Moffhub\Billing\Http\Resources\CouponResource;
use Moffhub\Billing\Models\Coupon;
use Moffhub\Billing\Models\PromotionCode;
use Moffhub\Billing\Services\CouponService;

class CouponController extends BillingController
{
    public function __construct(
        protected CouponService $couponService,
    ) {}

    /**
     * List all coupons (admin).
     */
    public function index(Request $request): JsonResponse
    {
        $coupons = Coupon::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->with('promotionCodes')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => CouponResource::collection($coupons->items()),
            'meta' => [
                'current_page' => $coupons->currentPage(),
                'last_page' => $coupons->lastPage(),
                'per_page' => $coupons->perPage(),
                'total' => $coupons->total(),
            ],
        ]);
    }

    /**
     * Create a coupon.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'discount_type' => ['required', 'string', 'in:percent,fixed'],
            'discount_value' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'duration' => ['required', 'string', 'in:once,repeating,forever'],
            'duration_in_months' => ['required_if:duration,repeating', 'nullable', 'integer', 'min:1', 'max:36'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'redeem_by' => ['nullable', 'date', 'after:now'],
            'is_active' => ['sometimes', 'boolean'],
            'applies_to_plans' => ['sometimes', 'array'],
            'applies_to_plans.*' => ['string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
        ]);

        // Validate percent is 1-100
        if ($validated['discount_type'] === 'percent' && $validated['discount_value'] > 100) {
            return response()->json(['message' => 'Percentage discount cannot exceed 100.'], 422);
        }

        $coupon = Coupon::create([
            'ulid' => Str::ulid()->toBase32(),
            ...$validated,
        ]);

        return response()->json([
            'message' => 'Coupon created.',
            'data' => new CouponResource($coupon),
        ], 201);
    }

    /**
     * Show a coupon.
     */
    public function show(int $coupon): JsonResponse
    {
        $coupon = Coupon::with('promotionCodes')->findOrFail($coupon);

        return response()->json([
            'data' => new CouponResource($coupon),
        ]);
    }

    /**
     * Deactivate a coupon.
     */
    public function destroy(int $coupon): JsonResponse
    {
        $coupon = Coupon::findOrFail($coupon);
        $coupon->update(['is_active' => false]);

        // Also deactivate all promotion codes
        $coupon->promotionCodes()->update(['is_active' => false]);

        return response()->json(['message' => 'Coupon and associated promotion codes deactivated.']);
    }

    /**
     * Create a promotion code for a coupon.
     */
    public function storePromotionCode(Request $request, int $coupon): JsonResponse
    {
        $couponModel = Coupon::query()->findOrFail($coupon);

        $promoTableRaw = config('billing.tables.promotion_codes', 'billing_promotion_codes');
        $promoTable = is_string($promoTableRaw) ? $promoTableRaw : 'billing_promotion_codes';

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:'.$promoTable.',code'],
            'first_time_transaction' => ['sometimes', 'boolean'],
            'minimum_amount' => ['nullable', 'integer', 'min:0'],
            'minimum_amount_currency' => ['nullable', 'string', 'size:3'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $codeValue = $validated['code'] ?? '';

        $promoCode = $couponModel->promotionCodes()->create([
            'code' => strtoupper(is_string($codeValue) ? $codeValue : ''),
            'is_active' => true,
            ...$validated,
        ]);

        /** @var PromotionCode $promoCode */
        return response()->json([
            'message' => 'Promotion code created.',
            'data' => [
                'id' => $promoCode->id,
                'code' => $promoCode->code,
                'coupon' => $couponModel->name,
                'discount' => $couponModel->discountDescription(),
                'is_active' => true,
            ],
        ], 201);
    }

    /**
     * Preview a discount code (customer-facing, no auth required).
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $result = $this->couponService->preview(
            $request->string('code')->toString(),
            $billable,
            $request->integer('amount'),
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Redeem a promotion code.
     */
    public function redeem(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'integer', 'min:1'],
            'subscription_id' => ['nullable', 'integer'],
            'invoice_id' => ['nullable', 'integer'],
        ]);

        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $subscriptionIdRaw = $request->input('subscription_id');
        $invoiceIdRaw = $request->input('invoice_id');

        try {
            $result = $this->couponService->applyCode(
                $request->string('code')->toString(),
                $billable,
                $request->integer('amount'),
                is_numeric($subscriptionIdRaw) ? (int) $subscriptionIdRaw : null,
                is_numeric($invoiceIdRaw) ? (int) $invoiceIdRaw : null,
            );

            return response()->json([
                'message' => "Discount applied: {$result['coupon']->discountDescription()}",
                'data' => [
                    'original_amount' => $request->integer('amount'),
                    'discount_amount' => $result['discount_amount'],
                    'final_amount' => $result['final_amount'],
                    'coupon' => $result['coupon']->name,
                    'description' => $result['coupon']->discountDescription(),
                ],
            ]);
        } catch (CouponException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
