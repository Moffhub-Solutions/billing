<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Moffhub\Billing\Enums\StandingOrderFrequency;
use Moffhub\Billing\Providers\MpesaProvider;
use Moffhub\Billing\Tests\BaseTestCase;

class MpesaRatibaProviderTest extends BaseTestCase
{
    /**
     * @param  array<string, mixed>  $ratiba
     */
    protected function provider(array $ratiba = ['enabled' => true, 'callback_url' => 'https://example.com/cb']): MpesaProvider
    {
        Cache::flush();

        return new MpesaProvider('key', 'secret', '600000', 'pass', 'sandbox', ratiba: $ratiba);
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderOptions(): array
    {
        return [
            'name' => 'Growth plan',
            'amount' => 500050,
            'phone' => '0712345678',
            'frequency' => StandingOrderFrequency::Quarterly,
            'start_date' => '2026-11-01',
            'end_date' => '2027-11-01',
            'account_reference' => 'ACME-001 long',
            'description' => 'Subscription fee for plan',
        ];
    }

    public function test_frequency_codes_map_to_safaricom_values(): void
    {
        $codes = array_map(fn (StandingOrderFrequency $f): string => $f->safaricomCode(), StandingOrderFrequency::cases());

        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], $codes);
    }

    public function test_disabled_by_default_makes_no_network_calls(): void
    {
        Http::fake();

        $result = $this->provider([])->createStandingOrder($this->orderOptions());

        $this->assertFalse($result['success']);
        $this->assertSame('Ratiba is not enabled for this deployment', $result['metadata']['error']);
        $this->assertFalse($this->provider([])->cancelStandingOrder('x')['success']);
        $this->assertFalse($this->provider([])->getStandingOrderStatus('x')['success']);
        Http::assertNothingSent();
    }

    public function test_create_sends_documented_payload(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/standingorder/v1/createStandingOrderExternal' => Http::response([
                'ResponseHeader' => ['responseRefID' => 'ref-1', 'responseCode' => '200'],
                'ResponseBody' => ['responseDescription' => 'Accepted'],
            ]),
        ]);

        $result = $this->provider()->createStandingOrder($this->orderOptions());

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame('ref-1', $result['provider_reference']);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'createStandingOrderExternal')) {
                return false;
            }
            $d = $request->data();

            return $d['StandingOrderName'] === 'Growth plan'
                && $d['StartDate'] === '20261101'
                && $d['EndDate'] === '20271101'
                && $d['BusinessShortCode'] === '600000'
                && $d['ReceiverPartyIdentifierType'] === '4'
                && $d['Amount'] === 5001
                && $d['PartyA'] === '254712345678'
                && $d['AccountReference'] === 'ACME001long'
                && $d['Frequency'] === '6'
                && $d['CallBackURL'] === 'https://example.com/cb'
                && strlen($d['TransactionDesc']) <= 13
                && $request->hasHeader('Authorization', 'Bearer tok');
        });
    }

    public function test_till_uses_till_identifier_type(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/createStandingOrderExternal' => Http::response(['ResponseHeader' => ['responseCode' => '200']]),
        ]);

        $this->provider(['enabled' => true, 'callback_url' => 'https://e.com/cb', 'transaction_type' => 'till'])
            ->createStandingOrder($this->orderOptions());

        Http::assertSent(fn ($r): bool => str_contains($r->url(), 'createStandingOrderExternal') && $r->data()['ReceiverPartyIdentifierType'] === '2');
    }

    public function test_rejected_response_is_a_failure(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/createStandingOrderExternal' => Http::response(['ResponseHeader' => ['responseCode' => '500']], 500),
        ]);

        $this->assertFalse($this->provider()->createStandingOrder($this->orderOptions())['success']);
    }

    public function test_validation_errors_throw(): void
    {
        Http::fake();
        $p = $this->provider();

        foreach ([
            ['amount' => 0],
            ['phone' => '12345'],
            ['name' => ''],
            ['frequency' => 'fortnightly'],
            ['end_date' => '2020-01-01'],
            ['transaction_type' => 'card'],
        ] as $override) {
            try {
                $p->createStandingOrder([...$this->orderOptions(), ...$override]);
                $this->fail('Expected InvalidArgumentException for '.json_encode($override));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_cancel_and_status_need_a_configured_endpoint(): void
    {
        Http::fake();
        $p = $this->provider();

        $this->assertFalse($p->cancelStandingOrder('ref-1')['success']);
        $this->assertFalse($p->getStandingOrderStatus('ref-1')['success']);
        Http::assertNothingSent();
    }

    public function test_cancel_and_status_with_configured_endpoints(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/so/cancel' => Http::response(['ResponseHeader' => ['responseCode' => '200']]),
            '*/so/status' => Http::response(['ResponseHeader' => ['responseCode' => '200'], 'ResponseBody' => ['Status' => 'Active']]),
        ]);
        $p = $this->provider(['enabled' => true, 'endpoints' => ['cancel' => '/so/cancel', 'status' => 'so/status']]);

        $this->assertTrue($p->cancelStandingOrder('ref-1')['success']);
        $status = $p->getStandingOrderStatus('ref-1');
        $this->assertTrue($status['success']);
        $this->assertSame('active', $status['status']);
    }

    public function test_parse_ratiba_and_c2b_callbacks(): void
    {
        $p = $this->provider();

        $a = $p->parseStandingOrderCallback([
            'ResponseHeader' => ['responseRefID' => 'r1', 'requestRefID' => 'q1'],
            'ResponseBody' => ['responseCode' => '0', 'TransactionID' => 'T1', 'Status' => 'Active'],
        ]);
        $this->assertTrue($a['success']);
        $this->assertSame('r1', $a['provider_reference']);
        $this->assertSame('T1', $a['transaction_id']);

        $b = $p->parseStandingOrderCallback(['TransID' => 'ABC', 'TransAmount' => '50.00', 'BillRefNumber' => 'REF1']);
        $this->assertTrue($b['success']);
        $this->assertSame(5000, $b['amount']);
        $this->assertSame('REF1', $b['account_reference']);

        $c = $p->parseStandingOrderCallback(['ResponseBody' => ['responseCode' => '1037', 'responseDescription' => 'Timeout']]);
        $this->assertFalse($c['success']);
    }
}
