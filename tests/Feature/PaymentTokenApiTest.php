<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Str;
use Moffhub\Billing\Models\PaymentToken;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class PaymentTokenApiTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Token Test Co']);
        $this->user = User::create([
            'name' => 'Token User',
            'email' => 'token@example.com',
            'company_id' => $this->company->id,
        ]);
    }

    // ─── List Tokens ────────────────────────────────────────────────────

    public function test_list_tokens_ordered_by_default_first_then_last_used(): void
    {
        $this->createToken([
            'is_default' => false,
            'last_used_at' => now()->subDays(3),
            'last_four' => '1111',
        ]);
        $this->createToken([
            'is_default' => true,
            'last_used_at' => now()->subDays(5),
            'last_four' => '2222',
        ]);
        $this->createToken([
            'is_default' => false,
            'last_used_at' => now()->subDay(),
            'last_four' => '3333',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/billing/payment-methods');

        $response->assertOk()
            ->assertJsonCount(3, 'data');

        // Default token should be first
        $this->assertEquals('2222', $response->json('data.0.last_four'));
        // Then most recently used
        $this->assertEquals('3333', $response->json('data.1.last_four'));
        $this->assertEquals('1111', $response->json('data.2.last_four'));
    }

    public function test_list_tokens_empty(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/billing/payment-methods');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ─── Store Token ────────────────────────────────────────────────────

    public function test_store_token_mpesa(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/payment-methods', [
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'mpesa_token_abc123',
            'last_four' => '5678',
            'phone' => '+254712345678',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Payment method saved.')
            ->assertJsonPath('data.provider', 'mpesa')
            ->assertJsonPath('data.token_type', 'mpesa')
            ->assertJsonPath('data.last_four', '5678')
            ->assertJsonPath('data.is_default', true); // First token auto-default
    }

    public function test_store_token_card_with_brand_and_expiry(): void
    {
        // Add a first token so the second one is not auto-default
        $this->createToken(['is_default' => true]);

        $response = $this->actingAs($this->user)->postJson('/api/billing/payment-methods', [
            'provider' => 'paystack',
            'token_type' => 'card',
            'token' => 'card_token_xyz789',
            'last_four' => '4242',
            'card_brand' => 'Visa',
            'card_exp_month' => '12',
            'card_exp_year' => '2028',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.card_brand', 'Visa')
            ->assertJsonPath('data.card_exp_month', '12')
            ->assertJsonPath('data.card_exp_year', '2028')
            ->assertJsonPath('data.is_default', false);
    }

    public function test_store_first_token_auto_default(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/payment-methods', [
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'tok_first',
            'last_four' => '0001',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_default', true);
    }

    public function test_store_setting_default_unsets_others(): void
    {
        $firstToken = $this->createToken(['is_default' => true, 'last_four' => '0001']);

        $response = $this->actingAs($this->user)->postJson('/api/billing/payment-methods', [
            'provider' => 'paystack',
            'token_type' => 'card',
            'token' => 'tok_new_default',
            'last_four' => '0002',
            'is_default' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_default', true);

        // Previous default should be unset
        $freshFirsttoken = $firstToken->fresh();
        $this->assertNotNull($freshFirsttoken);
        $this->assertFalse($freshFirsttoken->is_default);
    }

    public function test_store_token_validation(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/billing/payment-methods', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['provider', 'token_type', 'token']);
    }

    // ─── Set Default ────────────────────────────────────────────────────

    public function test_set_default_success(): void
    {
        $token1 = $this->createToken(['is_default' => true, 'last_four' => '1111']);
        $token2 = $this->createToken(['is_default' => false, 'last_four' => '2222']);

        $response = $this->actingAs($this->user)->putJson("/api/billing/payment-methods/{$token2->id}/default");

        $response->assertOk()
            ->assertJsonPath('message', 'Default payment method updated.')
            ->assertJsonPath('data.is_default', true);

        $freshToken1 = $token1->fresh();
        $this->assertNotNull($freshToken1);
        $this->assertFalse($freshToken1->is_default);
        $freshToken2 = $token2->fresh();
        $this->assertNotNull($freshToken2);
        $this->assertTrue($freshToken2->is_default);
    }

    public function test_set_default_unsets_previous(): void
    {
        $token1 = $this->createToken(['is_default' => true, 'last_four' => '3333']);
        $token2 = $this->createToken(['is_default' => false, 'last_four' => '4444']);
        $token3 = $this->createToken(['is_default' => false, 'last_four' => '5555']);

        $this->actingAs($this->user)->putJson("/api/billing/payment-methods/{$token3->id}/default");

        $freshToken1 = $token1->fresh();
        $this->assertNotNull($freshToken1);
        $this->assertFalse($freshToken1->is_default);
        $freshToken2 = $token2->fresh();
        $this->assertNotNull($freshToken2);
        $this->assertFalse($freshToken2->is_default);
        $freshToken3 = $token3->fresh();
        $this->assertNotNull($freshToken3);
        $this->assertTrue($freshToken3->is_default);
    }

    // ─── Destroy Token ──────────────────────────────────────────────────

    public function test_destroy_token_success(): void
    {
        $token = $this->createToken(['is_default' => false, 'last_four' => '9999']);

        $response = $this->actingAs($this->user)->deleteJson("/api/billing/payment-methods/{$token->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Payment method removed.');

        $this->assertDatabaseMissing(billing_table('payment_tokens', 'billing_payment_tokens'), [
            'id' => $token->id,
        ]);
    }

    public function test_deleting_default_promotes_next(): void
    {
        $default = $this->createToken([
            'is_default' => true,
            'last_four' => '1111',
            'last_used_at' => now()->subDays(5),
        ]);
        $next = $this->createToken([
            'is_default' => false,
            'last_four' => '2222',
            'last_used_at' => now()->subDay(),
        ]);
        $this->createToken([
            'is_default' => false,
            'last_four' => '3333',
            'last_used_at' => now()->subDays(3),
        ]);

        $this->actingAs($this->user)->deleteJson("/api/billing/payment-methods/{$default->id}");

        // The most recently used token should be promoted
        $freshNext = $next->fresh();
        $this->assertNotNull($freshNext);
        $this->assertTrue($freshNext->is_default);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createToken(array $attributes = []): PaymentToken
    {
        return $this->company->paymentTokens()->create(array_merge([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'tok_'.Str::random(16),
            'last_four' => '0000',
            'is_default' => false,
            'is_reusable' => true,
        ], $attributes));
    }
}
