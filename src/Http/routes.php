<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Moffhub\Billing\Http\Controllers\CouponController;
use Moffhub\Billing\Http\Controllers\FeatureController;
use Moffhub\Billing\Http\Controllers\InvoiceController;
use Moffhub\Billing\Http\Controllers\PaymentController;
use Moffhub\Billing\Http\Controllers\PaymentTokenController;
use Moffhub\Billing\Http\Controllers\PlanController;
use Moffhub\Billing\Http\Controllers\SubscriptionAddonController;
use Moffhub\Billing\Http\Controllers\SubscriptionController;
use Moffhub\Billing\Http\Controllers\UsageController;

/*
|--------------------------------------------------------------------------
| Billing API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with the configured billing route prefix
| (default: api/billing) and wrapped in configured middleware.
|
*/

// ─── Plans (public-facing, can be unauthenticated) ─────────────────────
Route::prefix('plans')->group(function (): void {
    Route::get('/', [PlanController::class, 'index'])->name('billing.plans.index');
    Route::get('/{plan}', [PlanController::class, 'show'])->name('billing.plans.show');
});

// ─── Admin Plan Management ─────────────────────────────────────────────
Route::prefix('admin/plans')->group(function (): void {
    Route::post('/', [PlanController::class, 'store'])->name('billing.plans.store');
    Route::put('/{plan}', [PlanController::class, 'update'])->name('billing.plans.update');
    Route::delete('/{plan}', [PlanController::class, 'destroy'])->name('billing.plans.destroy');
});

// ─── Features ──────────────────────────────────────────────────────────
Route::prefix('features')->group(function (): void {
    Route::get('/', [FeatureController::class, 'index'])->name('billing.features.index');
    Route::get('/addons', [FeatureController::class, 'addons'])->name('billing.features.addons');
    Route::get('/{feature}', [FeatureController::class, 'show'])->name('billing.features.show');
});

// ─── Admin Feature Management ──────────────────────────────────────────
Route::prefix('admin/features')->group(function (): void {
    Route::post('/', [FeatureController::class, 'store'])->name('billing.features.store');
    Route::put('/{feature}', [FeatureController::class, 'update'])->name('billing.features.update');
    Route::delete('/{feature}', [FeatureController::class, 'destroy'])->name('billing.features.destroy');
});

// ─── Subscriptions ─────────────────────────────────────────────────────
Route::prefix('subscriptions')->group(function (): void {
    Route::get('/', [SubscriptionController::class, 'index'])->name('billing.subscriptions.index');
    Route::post('/', [SubscriptionController::class, 'store'])->name('billing.subscriptions.store');
    Route::get('/current', [SubscriptionController::class, 'current'])->name('billing.subscriptions.current');
    Route::get('/{subscription}', [SubscriptionController::class, 'show'])->name('billing.subscriptions.show');
    Route::put('/{subscription}/change-plan', [SubscriptionController::class, 'changePlan'])->name('billing.subscriptions.change-plan');
    Route::post('/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->name('billing.subscriptions.cancel');
    Route::post('/{subscription}/pause', [SubscriptionController::class, 'pause'])->name('billing.subscriptions.pause');
    Route::post('/{subscription}/resume', [SubscriptionController::class, 'resume'])->name('billing.subscriptions.resume');

    // ─── Subscription Add-ons ──────────────────────────────────────
    Route::get('/{subscription}/addons', [SubscriptionAddonController::class, 'index'])->name('billing.subscriptions.addons.index');
    Route::post('/{subscription}/addons', [SubscriptionAddonController::class, 'store'])->name('billing.subscriptions.addons.store');
    Route::delete('/{subscription}/addons/{addon}', [SubscriptionAddonController::class, 'destroy'])->name('billing.subscriptions.addons.destroy');
});

// ─── Usage ─────────────────────────────────────────────────────────────
Route::prefix('usage')->group(function (): void {
    Route::get('/', [UsageController::class, 'index'])->name('billing.usage.index');
    Route::get('/{featureSlug}', [UsageController::class, 'show'])->name('billing.usage.show');
    Route::post('/{featureSlug}/record', [UsageController::class, 'record'])->name('billing.usage.record');
});

// ─── Payments ──────────────────────────────────────────────────────────
Route::prefix('payments')->group(function (): void {
    Route::get('/', [PaymentController::class, 'index'])->name('billing.payments.index');
    Route::post('/', [PaymentController::class, 'store'])->name('billing.payments.store');
    Route::get('/{payment}', [PaymentController::class, 'show'])->name('billing.payments.show');
    Route::post('/{payment}/refund', [PaymentController::class, 'refund'])->name('billing.payments.refund');
});

// ─── Coupons & Promotion Codes ─────────────────────────────────────────
Route::prefix('coupons')->group(function (): void {
    Route::post('/preview', [CouponController::class, 'preview'])->name('billing.coupons.preview');
    Route::post('/redeem', [CouponController::class, 'redeem'])->name('billing.coupons.redeem');
});

Route::prefix('admin/coupons')->group(function (): void {
    Route::get('/', [CouponController::class, 'index'])->name('billing.coupons.index');
    Route::post('/', [CouponController::class, 'store'])->name('billing.coupons.store');
    Route::get('/{coupon}', [CouponController::class, 'show'])->name('billing.coupons.show');
    Route::delete('/{coupon}', [CouponController::class, 'destroy'])->name('billing.coupons.destroy');
    Route::post('/{coupon}/promotion-codes', [CouponController::class, 'storePromotionCode'])->name('billing.coupons.promotion-codes.store');
});

// ─── Payment Methods / Tokens ──────────────────────────────────────────
Route::prefix('payment-methods')->group(function (): void {
    Route::get('/', [PaymentTokenController::class, 'index'])->name('billing.payment-methods.index');
    Route::post('/', [PaymentTokenController::class, 'store'])->name('billing.payment-methods.store');
    Route::put('/{token}/default', [PaymentTokenController::class, 'setDefault'])->name('billing.payment-methods.default');
    Route::delete('/{token}', [PaymentTokenController::class, 'destroy'])->name('billing.payment-methods.destroy');
});

// ─── Invoices ──────────────────────────────────────────────────────────
Route::prefix('invoices')->group(function (): void {
    Route::get('/', [InvoiceController::class, 'index'])->name('billing.invoices.index');
    Route::post('/', [InvoiceController::class, 'store'])->name('billing.invoices.store');
    Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('billing.invoices.show');
    Route::post('/{invoice}/send', [InvoiceController::class, 'send'])->name('billing.invoices.send');
    Route::post('/{invoice}/void', [InvoiceController::class, 'void'])->name('billing.invoices.void');
    Route::post('/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('billing.invoices.mark-paid');
});
