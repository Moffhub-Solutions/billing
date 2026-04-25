<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Moffhub\Billing\Security\FieldEncryptor;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class EncryptionTest extends BaseTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Test Co']);
    }

    public function test_field_encryptor_encrypts_and_decrypts(): void
    {
        $encryptor = new FieldEncryptor;

        $data = [
            'phone' => '254712345678',
            'email' => 'test@example.com',
            'name' => 'John Doe',
        ];

        $encrypted = $encryptor->encrypt($data, ['phone', 'email']);

        $encryptedPhone = $encrypted['phone'] ?? null;
        $encryptedEmail = $encrypted['email'] ?? null;
        $this->assertIsString($encryptedPhone);
        $this->assertIsString($encryptedEmail);

        // Phone and email should be encrypted (longer than original due to ciphertext)
        $this->assertNotEquals(strlen('254712345678'), strlen($encryptedPhone));
        $this->assertNotEquals(strlen('test@example.com'), strlen($encryptedEmail));
        // Name should be untouched
        $this->assertEquals('John Doe', $encrypted['name']);

        // Decrypt should restore
        $decrypted = $encryptor->decrypt($encrypted, ['phone', 'email']);
        $this->assertEquals('254712345678', $decrypted['phone']);
        $this->assertEquals('test@example.com', $decrypted['email']);
    }

    public function test_field_encryptor_graceful_decrypt_of_plaintext(): void
    {
        $encryptor = new FieldEncryptor;

        // Simulate data that was never encrypted (migration period)
        $data = ['phone' => '254712345678'];

        $decrypted = $encryptor->decrypt($data, ['phone']);

        // Should return as-is, not throw
        $this->assertEquals('254712345678', $decrypted['phone']);
    }

    public function test_field_encryptor_mask(): void
    {
        $encryptor = new FieldEncryptor;

        $this->assertEquals('2547****5678', $encryptor->mask('254712345678'));
        $this->assertEquals('test*******e.com', $encryptor->mask('test@example.com', 4, 5));
        $this->assertEquals('***', $encryptor->mask('abc', 4, 4)); // too short, all masked
    }

    public function test_field_encryptor_scrub_for_logging(): void
    {
        $encryptor = new FieldEncryptor;

        $data = [
            'provider' => 'mpesa',
            'token' => 'secret_auth_xxxxx',
            'amount' => 5000,
            'nested' => [
                'api_key' => 'my_api_key',
                'name' => 'visible',
            ],
        ];

        $scrubbed = $encryptor->scrubForLogging($data);

        $this->assertEquals('***REDACTED***', $scrubbed['token']);
        $nested = $scrubbed['nested'] ?? null;
        $this->assertIsArray($nested);
        $this->assertEquals('***REDACTED***', $nested['api_key']);
        $this->assertEquals('mpesa', $scrubbed['provider']);
        $this->assertEquals(5000, $scrubbed['amount']);
        $this->assertEquals('visible', $nested['name']);
    }

    public function test_payment_token_encrypts_pii_when_enabled(): void
    {
        // Enable encryption
        config(['billing.security.encrypt_at_rest' => true]);

        $token = $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'AUTH_secret_token_12345',
            'phone' => '254712345678',
            'email' => 'customer@example.com',
            'is_reusable' => true,
        ]);

        // Reading through the model should give us decrypted values
        $this->assertEquals('AUTH_secret_token_12345', $token->token);
        $this->assertEquals('254712345678', $token->phone);
        $this->assertEquals('customer@example.com', $token->email);

        // Raw database values should be encrypted (not plaintext)
        $raw = DB::table(billing_table('payment_tokens', 'billing_payment_tokens'))->find($token->id);
        $this->assertInstanceOf(\stdClass::class, $raw);
        $rawToken = $raw->token ?? null;
        $rawPhone = $raw->phone ?? null;
        $rawEmail = $raw->email ?? null;
        $this->assertIsString($rawToken);
        $this->assertIsString($rawPhone);
        $this->assertIsString($rawEmail);
        $this->assertNotEquals('AUTH_secret_token_12345', $rawToken);
        $this->assertNotEquals('254712345678', $rawPhone);
        $this->assertNotEquals('customer@example.com', $rawEmail);

        // Verify raw values are decryptable
        $this->assertEquals('AUTH_secret_token_12345', Crypt::decryptString($rawToken));
        $this->assertEquals('254712345678', Crypt::decryptString($rawPhone));
    }

    public function test_payment_token_skips_encryption_when_disabled(): void
    {
        // Encryption disabled (default)
        config(['billing.security.encrypt_at_rest' => false]);

        $token = $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'AUTH_plaintext_token',
            'phone' => '254712345678',
            'is_reusable' => true,
        ]);

        // Raw database values should be plaintext
        $raw = DB::table(billing_table('payment_tokens', 'billing_payment_tokens'))->find($token->id);
        $this->assertInstanceOf(\stdClass::class, $raw);
        $this->assertEquals('AUTH_plaintext_token', $raw->token ?? null);
        $this->assertEquals('254712345678', $raw->phone ?? null);
    }

    public function test_payment_token_hides_token_in_serialization(): void
    {
        $token = $this->company->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'provider' => 'mpesa',
            'token_type' => 'mpesa',
            'token' => 'AUTH_should_be_hidden',
            'is_reusable' => true,
        ]);

        $array = $token->toArray();

        $this->assertArrayNotHasKey('token', $array);
    }

    public function test_field_encryptor_hash_is_consistent(): void
    {
        $encryptor = new FieldEncryptor;

        $hash1 = $encryptor->hash('254712345678');
        $hash2 = $encryptor->hash('254712345678');
        $hash3 = $encryptor->hash('254799999999');

        $this->assertEquals($hash1, $hash2); // same input = same hash
        $this->assertNotEquals($hash1, $hash3); // different input = different hash
    }
}
