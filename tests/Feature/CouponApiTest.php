<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Str;
use Moffhub\Billing\Models\Coupon;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class CouponApiTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Coupon Test Co']);
        $this->user = User::create([
            'name' => 'Coupon User',
            'email' => 'coupon@example.com',
            'company_id' => $this->company->id,
        ]);

        // Admin coupon management routes are gated; authenticate as an admin.
        $this->actingAs($this->user);
        $this->grantBillingAdmin();
    }

    // ─── List Coupons ───────────────────────────────────────────────────

    public function test_list_coupons_active_only(): void
    {
        $this->createCoupon(['is_active' => true, 'name' => 'Active One']);
        $this->createCoupon(['is_active' => true, 'name' => 'Active Two']);
        $this->createCoupon(['is_active' => false, 'name' => 'Inactive']);

        $response = $this->actingAs($this->user)->getJson('/api/billing/admin/coupons');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_list_coupons_include_inactive(): void
    {
        $this->createCoupon(['is_active' => true]);
        $this->createCoupon(['is_active' => false]);

        $response = $this->actingAs($this->user)->getJson('/api/billing/admin/coupons?include_inactive=1');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_list_coupons_pagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createCoupon(['is_active' => true, 'name' => "Coupon {$i}"]);
        }

        $response = $this->actingAs($this->user)->getJson('/api/billing/admin/coupons?per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5);
    }

    // ─── Create Coupon ──────────────────────────────────────────────────

    public function test_create_coupon_percent(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/admin/coupons', [
            'name' => '20% Off',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'duration' => 'once',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Coupon created.')
            ->assertJsonPath('data.name', '20% Off')
            ->assertJsonPath('data.discount_type', 'percent')
            ->assertJsonPath('data.discount_value', 20);

        $this->assertDatabaseHas(billing_table('coupons', 'billing_coupons'), [
            'name' => '20% Off',
            'discount_type' => 'percent',
        ]);
    }

    public function test_create_coupon_fixed(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/admin/coupons', [
            'name' => 'KES 500 Off',
            'discount_type' => 'fixed',
            'discount_value' => 50000,
            'duration' => 'forever',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.discount_type', 'fixed')
            ->assertJsonPath('data.discount_value', 50000);
    }

    public function test_create_coupon_validation_errors(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/admin/coupons', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'discount_type', 'discount_value', 'duration']);
    }

    public function test_create_coupon_percent_over_100(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/admin/coupons', [
            'name' => 'Invalid',
            'discount_type' => 'percent',
            'discount_value' => 150,
            'duration' => 'once',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Percentage discount cannot exceed 100.');
    }

    // ─── Show Coupon ────────────────────────────────────────────────────

    public function test_show_coupon_with_promo_codes(): void
    {
        $coupon = $this->createCoupon(['is_active' => true]);
        $coupon->promotionCodes()->create([
            'code' => 'PROMO1',
            'is_active' => true,
        ]);
        $coupon->promotionCodes()->create([
            'code' => 'PROMO2',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/billing/admin/coupons/{$coupon->id}");

        $response->assertOk()
            ->assertJsonPath('data.name', $coupon->name)
            ->assertJsonCount(2, 'data.promotion_codes');
    }

    // ─── Deactivate Coupon ──────────────────────────────────────────────

    public function test_deactivate_coupon_deactivates_codes_too(): void
    {
        $coupon = $this->createCoupon(['is_active' => true]);
        $code1 = $coupon->promotionCodes()->create(['code' => 'CODE1', 'is_active' => true]);
        $code2 = $coupon->promotionCodes()->create(['code' => 'CODE2', 'is_active' => true]);

        $response = $this->actingAs($this->user)->deleteJson("/api/billing/admin/coupons/{$coupon->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Coupon and associated promotion codes deactivated.');

        $couponFresh = $coupon->fresh();
        $code1Fresh = $code1->fresh();
        $code2Fresh = $code2->fresh();
        $this->assertNotNull($couponFresh);
        $this->assertNotNull($code1Fresh);
        $this->assertNotNull($code2Fresh);
        $this->assertFalse($couponFresh->is_active);
        $this->assertFalse($code1Fresh->is_active);
        $this->assertFalse($code2Fresh->is_active);
    }

    // ─── Create Promotion Code ──────────────────────────────────────────

    public function test_create_promotion_code_success(): void
    {
        $coupon = $this->createCoupon(['is_active' => true]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/admin/coupons/{$coupon->id}/promotion-codes", [
            'code' => 'SAVE20',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Promotion code created.')
            ->assertJsonPath('data.code', 'SAVE20')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas(billing_table('promotion_codes', 'billing_promotion_codes'), [
            'code' => 'SAVE20',
            'coupon_id' => $coupon->id,
        ]);
    }

    public function test_create_promotion_code_duplicate_code(): void
    {
        $coupon = $this->createCoupon(['is_active' => true]);
        $coupon->promotionCodes()->create(['code' => 'DUPLICATE', 'is_active' => true]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/admin/coupons/{$coupon->id}/promotion-codes", [
            'code' => 'DUPLICATE',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_create_promotion_code_validation(): void
    {
        $coupon = $this->createCoupon(['is_active' => true]);

        $response = $this->actingAs($this->user)->postJson("/api/billing/admin/coupons/{$coupon->id}/promotion-codes", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    // ─── Preview Code ───────────────────────────────────────────────────

    public function test_preview_valid_code(): void
    {
        $coupon = $this->createCoupon([
            'is_active' => true,
            'discount_type' => 'percent',
            'discount_value' => 25,
        ]);
        $coupon->promotionCodes()->create(['code' => 'PREVIEW25', 'is_active' => true]);

        $response = $this->actingAs($this->user)->postJson('/api/billing/coupons/preview', [
            'code' => 'PREVIEW25',
            'amount' => 100000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.code', 'PREVIEW25')
            ->assertJsonPath('data.discount_amount', 25000)
            ->assertJsonPath('data.final_amount', 75000);
    }

    public function test_preview_invalid_code(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/coupons/preview', [
            'code' => 'NONEXISTENT',
            'amount' => 100000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_preview_expired_code(): void
    {
        $coupon = $this->createCoupon([
            'is_active' => true,
            'discount_type' => 'percent',
            'discount_value' => 10,
        ]);
        $coupon->promotionCodes()->create([
            'code' => 'EXPIRED',
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/billing/coupons/preview', [
            'code' => 'EXPIRED',
            'amount' => 100000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    // ─── Redeem Code ────────────────────────────────────────────────────

    public function test_redeem_code_success(): void
    {
        $coupon = $this->createCoupon([
            'is_active' => true,
            'discount_type' => 'percent',
            'discount_value' => 10,
        ]);
        $coupon->promotionCodes()->create(['code' => 'REDEEM10', 'is_active' => true]);

        $response = $this->actingAs($this->user)->postJson('/api/billing/coupons/redeem', [
            'code' => 'REDEEM10',
            'amount' => 200000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.original_amount', 200000)
            ->assertJsonPath('data.discount_amount', 20000)
            ->assertJsonPath('data.final_amount', 180000);
    }

    public function test_redeem_invalid_code(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/coupons/redeem', [
            'code' => 'INVALID',
            'amount' => 100000,
        ]);

        $response->assertUnprocessable();
    }

    public function test_redeem_code_restriction_failed(): void
    {
        $coupon = $this->createCoupon([
            'is_active' => true,
            'discount_type' => 'fixed',
            'discount_value' => 5000,
        ]);
        $coupon->promotionCodes()->create([
            'code' => 'MINAMOUNT',
            'is_active' => true,
            'minimum_amount' => 100000,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/billing/coupons/redeem', [
            'code' => 'MINAMOUNT',
            'amount' => 5000,
        ]);

        $response->assertUnprocessable();
    }

    public function test_redeem_code_increments_counters(): void
    {
        $coupon = $this->createCoupon([
            'is_active' => true,
            'discount_type' => 'percent',
            'discount_value' => 5,
            'times_redeemed' => 0,
        ]);
        $promoCode = $coupon->promotionCodes()->create([
            'code' => 'COUNTER5',
            'is_active' => true,
            'times_redeemed' => 0,
        ]);

        $this->actingAs($this->user)->postJson('/api/billing/coupons/redeem', [
            'code' => 'COUNTER5',
            'amount' => 100000,
        ]);

        $couponFresh = $coupon->fresh();
        $promoFresh = $promoCode->fresh();
        $this->assertNotNull($couponFresh);
        $this->assertNotNull($promoFresh);
        $this->assertEquals(1, $couponFresh->times_redeemed);
        $this->assertEquals(1, $promoFresh->times_redeemed);
    }

    public function test_redeem_code_no_billable(): void
    {
        $orphanUser = User::create([
            'name' => 'Orphan',
            'email' => 'orphan-coupon@example.com',
            'company_id' => null,
        ]);

        $response = $this->actingAs($orphanUser)->postJson('/api/billing/coupons/redeem', [
            'code' => 'ANYTHING',
            'amount' => 100000,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('message', 'No billable entity found.');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createCoupon(array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Test Coupon',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'duration' => 'once',
            'is_active' => true,
        ], $attributes));
    }
}
