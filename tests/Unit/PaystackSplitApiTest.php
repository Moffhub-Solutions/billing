<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Exceptions\PaystackException;
use Moffhub\Billing\Providers\PaystackProvider;
use Moffhub\Billing\Services\PaystackSplitApi;
use Moffhub\Billing\Tests\BaseTestCase;

class PaystackSplitApiTest extends BaseTestCase
{
    private function api(string $key = 'sk_test_x'): PaystackSplitApi
    {
        return new PaystackSplitApi($key);
    }

    #[\Override]
    protected function tearDown(): void
    {
        PaystackProvider::resolveDefaultSplitUsing(null);

        parent::tearDown();
    }

    public function test_it_lists_banks_for_a_country(): void
    {
        Http::fake(['*/bank*' => Http::response(['status' => true, 'data' => [['code' => '01', 'name' => 'KCB']]])]);

        $banks = $this->api()->listBanks();

        $this->assertSame('KCB', $banks[0]['name']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'country=kenya'));
    }

    public function test_it_creates_a_subaccount_and_returns_its_data(): void
    {
        Http::fake(['*/subaccount' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_1']])]);

        $data = $this->api()->createSubaccount(['business_name' => 'Moffat', 'account_number' => '123']);

        $this->assertSame('ACCT_1', $data['subaccount_code']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer sk_test_x')
            && $request['business_name'] === 'Moffat');
    }

    public function test_it_updates_and_fetches_a_subaccount(): void
    {
        Http::fake(['*/subaccount/ACCT_1' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_1']])]);

        $this->api()->updateSubaccount('ACCT_1', ['active' => false]);
        $this->api()->fetchSubaccount('ACCT_1');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_it_manages_a_split(): void
    {
        Http::fake(['*/split*' => Http::response(['status' => true, 'data' => ['split_code' => 'SPL_1', 'id' => 7]])]);

        $api = $this->api();
        $this->assertSame('SPL_1', $api->createSplit(['name' => 'Cynet', 'type' => 'percentage'])['split_code']);
        $api->updateSplit('SPL_1', ['active' => true]);
        $api->fetchSplit('SPL_1');
        $api->addOrUpdateSplitSubaccount('SPL_1', 'ACCT_1', 25);
        $api->removeSplitSubaccount('SPL_1', 'ACCT_1');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/split/SPL_1/subaccount/add')
            && $request['subaccount'] === 'ACCT_1' && $request['share'] === 25);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/split/SPL_1/subaccount/remove'));
    }

    public function test_a_rejected_request_throws_with_paystacks_message(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Invalid account number'], 400)]);

        $this->expectException(PaystackException::class);
        $this->expectExceptionMessage('Invalid account number');

        $this->api()->createSubaccount([]);
    }

    public function test_a_missing_key_throws_without_calling_paystack(): void
    {
        Http::fake();

        try {
            $this->api('')->listBanks();
            $this->fail('Expected a PaystackException.');
        } catch (PaystackException $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_charges_use_the_configured_default_split_for_the_matching_currency(): void
    {
        Http::fake(['*/transaction/initialize' => Http::response(['status' => true, 'data' => ['reference' => 'r']])]);
        config([
            'billing.providers.paystack.split_code' => 'SPL_default',
            'billing.providers.paystack.split_currency' => 'KES',
        ]);

        $provider = new PaystackProvider('sk_test_x');
        $provider->initializeTransaction('a@b.co', 1000, 'KES');
        $provider->initializeTransaction('a@b.co', 1000, 'NGN');

        Http::assertSent(fn (Request $request): bool => $request['currency'] === 'KES' && $request['split_code'] === 'SPL_default');
        Http::assertSent(fn (Request $request): bool => $request['currency'] === 'NGN' && ! isset($request['split_code']));
    }

    public function test_explicit_split_options_win_over_the_default(): void
    {
        Http::fake(['*/transaction/initialize' => Http::response(['status' => true, 'data' => ['reference' => 'r']])]);
        config(['billing.providers.paystack.split_code' => 'SPL_default']);

        (new PaystackProvider('sk_test_x'))->initializeTransaction('a@b.co', 1000, 'KES', ['split_code' => 'SPL_own']);

        Http::assertSent(fn (Request $request): bool => $request['split_code'] === 'SPL_own');
    }

    public function test_a_resolver_overrides_config_and_covers_charge_authorization(): void
    {
        Http::fake(['*/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'success']])]);
        PaystackProvider::resolveDefaultSplitUsing(fn (string $currency): ?array => $currency === 'KES' ? ['split_code' => 'SPL_app'] : null);

        (new PaystackProvider('sk_test_x'))->chargeAuthorization('a@b.co', 1000, 'KES', 'AUTH_1');

        Http::assertSent(fn (Request $request): bool => $request['split_code'] === 'SPL_app');
    }
}
