<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Str;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class PaymentTokenTest extends BaseTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Test Co']);
    }

    public function test_can_create_mpesa_token(): void
    {
        $token = $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'mpesa_auth_xxxxx',
            'last_four' => '5678',
            'phone' => '254712345678',
            'is_default' => true,
            'is_reusable' => true,
        ]);

        $this->assertEquals('M-Pesa ...5678', $token->displayLabel());
        $this->assertTrue($token->isUsable());
        $this->assertTrue($token->is_default);
    }

    public function test_can_create_card_token(): void
    {
        $token = $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'paystack',
            'token_type' => 'card',
            'token' => 'AUTH_xxxxxxxx',
            'last_four' => '4242',
            'card_brand' => 'Visa',
            'card_exp_month' => '12',
            'card_exp_year' => '2028',
            'email' => 'customer@example.com',
            'is_default' => false,
            'is_reusable' => true,
        ]);

        $this->assertEquals('Visa ...4242', $token->displayLabel());
        $this->assertTrue($token->isUsable());
    }

    public function test_expired_token_is_not_usable(): void
    {
        $token = $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'paystack',
            'token_type' => 'card',
            'token' => 'AUTH_expired',
            'is_reusable' => true,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($token->isUsable());
    }

    public function test_non_reusable_token_is_not_usable(): void
    {
        $token = $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'one_time_token',
            'is_reusable' => false,
        ]);

        $this->assertFalse($token->isUsable());
    }

    public function test_default_payment_token(): void
    {
        $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'token_1',
            'is_default' => false,
            'is_reusable' => true,
        ]);

        $default = $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'paystack',
            'token_type' => 'card',
            'token' => 'token_2',
            'is_default' => true,
            'is_reusable' => true,
        ]);

        $defaultToken = $this->company->defaultPaymentToken();
        $this->assertNotNull($defaultToken);
        $this->assertEquals($default->id, $defaultToken->id);
    }

    public function test_usable_scope(): void
    {
        $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'usable_token',
            'is_reusable' => true,
        ]);

        $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'expired_token',
            'is_reusable' => true,
            'expires_at' => now()->subDay(),
        ]);

        $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'non_reusable',
            'is_reusable' => false,
        ]);

        $this->assertEquals(1, $this->company->paymentTokens()->usable()->count());
    }
}
