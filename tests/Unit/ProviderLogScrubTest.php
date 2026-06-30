<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Providers\ManualProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class ProviderLogScrubTest extends BaseTestCase
{
    /**
     * Invoke the provider's protected scrubber for assertions.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data): array
    {
        $method = new \ReflectionMethod(ManualProvider::class, 'scrubSensitiveData');
        $result = $method->invoke(new ManualProvider, $data);

        $this->assertIsArray($result);

        return $result;
    }

    public function test_redacts_secret_pii_and_credential_keys(): void
    {
        $scrubbed = $this->scrub([
            'amount' => 1000,
            'secret_key' => 'sk_live_abc',
            'consumer_key' => 'ck_abc',
            'client_secret' => 'cs_abc',
            'api_secret' => 'as_abc',
            'access_token' => 'at_abc',
            'Authorization' => 'Bearer xyz',
            'passkey' => 'pk_abc',
            'phone' => '254712345678',
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'nested' => ['secret' => 'deep', 'public_key' => 'pub', 'keep' => 'ok'],
        ]);

        $this->assertSame(1000, $scrubbed['amount']);
        $this->assertSame('***REDACTED***', $scrubbed['secret_key']);
        $this->assertSame('***REDACTED***', $scrubbed['consumer_key']);
        $this->assertSame('***REDACTED***', $scrubbed['client_secret']);
        $this->assertSame('***REDACTED***', $scrubbed['api_secret']);
        $this->assertSame('***REDACTED***', $scrubbed['access_token']);
        $this->assertSame('***REDACTED***', $scrubbed['Authorization']);
        $this->assertSame('***REDACTED***', $scrubbed['passkey']);
        $this->assertSame('***REDACTED***', $scrubbed['phone']);
        $this->assertSame('***REDACTED***', $scrubbed['card_number']);
        $this->assertSame('***REDACTED***', $scrubbed['cvv']);

        $this->assertIsArray($scrubbed['nested']);
        $this->assertSame('***REDACTED***', $scrubbed['nested']['secret']);
        $this->assertSame('***REDACTED***', $scrubbed['nested']['public_key']);
        $this->assertSame('ok', $scrubbed['nested']['keep']);
    }

    public function test_honors_configured_scrub_keys(): void
    {
        $this->setConfig('billing.security.scrub_keys', ['custom_field']);

        $scrubbed = $this->scrub(['custom_field' => 'sensitive', 'plain' => 'visible']);

        $this->assertSame('***REDACTED***', $scrubbed['custom_field']);
        $this->assertSame('visible', $scrubbed['plain']);
    }
}
