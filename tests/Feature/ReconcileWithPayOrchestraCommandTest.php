<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\ReconciliationDrift;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;

class ReconcileWithPayOrchestraCommandTest extends BaseTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.providers.payorchestra' => [
                'api_key' => 'po_test_xxx',
                'org_id' => 'org_test',
                'webhook_secret' => 'whsec_test',
                'base_url' => 'https://backbone.test',
            ],
        ]);
    }

    public function test_no_drift_when_billing_and_backbone_agree(): void
    {
        $company = Company::create(['name' => 'Cynet Test']);
        $this->createPayment($company, 'pi_agree', PaymentStatus::COMPLETED);

        Http::fake([
            'https://backbone.test/api/v1/client/payment-intents/pi_agree' => Http::response([
                'data' => ['id' => 'pi_agree', 'status' => 'completed'],
            ]),
        ]);

        $result = $this->artisan('billing:reconcile-payorchestra', ['--hours' => 24]);
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->assertSuccessful();
        $result->run();

        $this->assertSame(0, ReconciliationDrift::count());
    }

    public function test_drift_recorded_when_statuses_disagree(): void
    {
        $company = Company::create(['name' => 'Cynet Test']);
        $payment = $this->createPayment($company, 'pi_drift', PaymentStatus::PENDING);

        Http::fake([
            'https://backbone.test/api/v1/client/payment-intents/pi_drift' => Http::response([
                'data' => ['id' => 'pi_drift', 'status' => 'completed'],
            ]),
        ]);

        // Drift detected → command exits with FAILURE so cron can alert.
        $result = $this->artisan('billing:reconcile-payorchestra', ['--hours' => 24]);
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->assertExitCode(1);
        $result->run();

        $this->assertSame(1, ReconciliationDrift::count());

        $drift = ReconciliationDrift::first();
        $this->assertNotNull($drift);
        $this->assertSame($payment->id, $drift->payment_id);
        $this->assertSame('pending', $drift->billing_status);
        $this->assertSame('completed', $drift->provider_status);
        $this->assertNull($drift->resolved_at);
    }

    public function test_dry_run_detects_drift_without_persisting(): void
    {
        $company = Company::create(['name' => 'Cynet Test']);
        $this->createPayment($company, 'pi_dry', PaymentStatus::PENDING);

        Http::fake([
            'https://backbone.test/api/v1/client/payment-intents/pi_dry' => Http::response([
                'data' => ['id' => 'pi_dry', 'status' => 'failed'],
            ]),
        ]);

        $result = $this->artisan('billing:reconcile-payorchestra', ['--hours' => 24, '--dry-run' => true]);
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->assertExitCode(1);
        $result->run();

        $this->assertSame(0, ReconciliationDrift::count());
    }

    public function test_pending_and_processing_treated_as_compatible(): void
    {
        $company = Company::create(['name' => 'Cynet Test']);
        $this->createPayment($company, 'pi_inflight', PaymentStatus::PENDING);

        // Backbone is mid-flight; billing is still pending — not a drift.
        Http::fake([
            'https://backbone.test/api/v1/client/payment-intents/pi_inflight' => Http::response([
                'data' => ['id' => 'pi_inflight', 'status' => 'processing'],
            ]),
        ]);

        $result = $this->artisan('billing:reconcile-payorchestra', ['--hours' => 24]);
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->assertSuccessful();
        $result->run();

        $this->assertSame(0, ReconciliationDrift::count());
    }

    public function test_skips_payments_outside_window(): void
    {
        $company = Company::create(['name' => 'Cynet Test']);
        $payment = $this->createPayment($company, 'pi_old', PaymentStatus::PENDING);
        $payment->updated_at = now()->subDays(5);
        $payment->saveQuietly();

        // No HTTP fake needed — the command shouldn't even ask backbone.
        $result = $this->artisan('billing:reconcile-payorchestra', ['--hours' => 24]);
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->assertSuccessful();
        $result->run();

        $this->assertSame(0, ReconciliationDrift::count());
    }

    public function test_only_inspects_payorchestra_provider(): void
    {
        $company = Company::create(['name' => 'Cynet Test']);
        $payment = $this->createPayment($company, 'pi_other', PaymentStatus::PENDING);
        $payment->payment_provider = 'paystack';
        $payment->save();

        // No HTTP fake needed — non-payorchestra payments are skipped.
        $result = $this->artisan('billing:reconcile-payorchestra', ['--hours' => 24]);
        $this->assertInstanceOf(PendingCommand::class, $result);
        $result->assertSuccessful();
        $result->run();

        $this->assertSame(0, ReconciliationDrift::count());
    }

    private function createPayment(Company $company, string $providerPaymentId, PaymentStatus $status): Payment
    {
        $payment = new Payment([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 10000,
            'currency' => 'KES',
            'status' => $status,
            'payment_provider' => 'payorchestra',
            'provider_payment_id' => $providerPaymentId,
        ]);

        $company->payments()->save($payment);

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh);

        return $fresh;
    }
}
