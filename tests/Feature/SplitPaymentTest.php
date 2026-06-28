<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Enums\InvoiceStatus;
use Moffhub\Billing\Enums\PaymentStatus;
use Moffhub\Billing\Models\Invoice;
use Moffhub\Billing\Models\Payment;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\PaymentManager;
use Moffhub\Billing\Services\SplitPaymentService;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Tests\Fixtures\Models\User;

class SplitPaymentTest extends BaseTestCase
{
    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Split Co']);
        $this->user = User::create([
            'name' => 'Split User',
            'email' => 'split@example.com',
            'company_id' => $this->company->id,
        ]);

        // Manual provider settles to "pending"; give it a per-transaction cap.
        $this->setConfig('billing.providers.manual.limits', ['max_amount' => 25_000_000]);
    }

    protected function service(): SplitPaymentService
    {
        return app(SplitPaymentService::class);
    }

    // ─── Splitter integration ──────────────────────────────────────────

    public function test_process_creates_group_and_initiates_first_tranche(): void
    {
        $result = $this->service()->process($this->company, 'manual', 50_000_000, 'KES');

        $this->assertTrue($result['split']);
        $this->assertNotNull($result['group']);
        $this->assertSame(2, $result['tranche_count']);
        $this->assertCount(2, $result['payments']);

        $this->assertDatabaseCount('billing_payments', 2);

        $tranches = Payment::query()->where('payment_group', $result['group'])->orderBy('group_sequence')->get();
        $this->assertSame([25_000_000, 25_000_000], $tranches->pluck('amount')->all());
        $this->assertSame([1, 2], $tranches->pluck('group_sequence')->all());
        $this->assertSame([2, 2], $tranches->pluck('group_size')->all());

        // First tranche was initiated, second is still awaiting collection.
        $group = (string) $result['group'];
        $first = Payment::query()->where('payment_group', $group)->where('group_sequence', 1)->firstOrFail();
        $second = Payment::query()->where('payment_group', $group)->where('group_sequence', 2)->firstOrFail();
        $this->assertNotNull($first->provider_payment_id);
        $this->assertNull($second->provider_payment_id);
    }

    public function test_amount_within_limit_is_not_split(): void
    {
        $result = $this->service()->process($this->company, 'manual', 20_000_000, 'KES');

        $this->assertFalse($result['split']);
        $this->assertNull($result['group']);
        $this->assertCount(1, $result['payments']);
        $this->assertNull($result['payments'][0]->payment_group);
    }

    // ─── Auto-advance (sequential collection) ──────────────────────────

    public function test_advance_initiates_next_tranche_when_one_completes(): void
    {
        $result = $this->service()->process($this->company, 'manual', 75_000_000, 'KES'); // 3 tranches
        $first = Payment::query()->where('payment_group', $result['group'])->where('group_sequence', 1)->firstOrFail();

        // Simulate the first tranche settling (as a webhook would).
        $first->forceFill(['status' => PaymentStatus::COMPLETED, 'paid_at' => now()])->save();

        $next = $this->service()->advance($first->refresh(), $this->company);

        $this->assertNotNull($next);
        $this->assertSame(2, $next->group_sequence);
        $this->assertNotNull($next->provider_payment_id); // it was initiated
    }

    public function test_advance_returns_null_when_auto_advance_disabled(): void
    {
        $this->setConfig('billing.split_payments.auto_advance', false);

        $result = $this->service()->process($this->company, 'manual', 50_000_000, 'KES');
        $first = Payment::query()->where('payment_group', $result['group'])->where('group_sequence', 1)->firstOrFail();
        $second = Payment::query()->where('payment_group', $result['group'])->where('group_sequence', 2)->firstOrFail();
        $first->forceFill(['status' => PaymentStatus::COMPLETED])->save();

        $this->assertNull($this->service()->advance($first->refresh(), $this->company));
        // Second tranche untouched.
        $this->assertNull($second->refresh()->provider_payment_id);
    }

    public function test_used_today_counts_toward_daily_cap(): void
    {
        $this->company->payments()->create([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 25_000_000,
            'currency' => 'KES',
            'status' => PaymentStatus::COMPLETED,
            'payment_provider' => 'manual',
        ]);

        $this->assertSame(1, $this->service()->usedToday($this->company, 'manual'));
        // Failed payments don't count.
        $this->company->payments()->create([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 25_000_000,
            'currency' => 'KES',
            'status' => PaymentStatus::FAILED,
            'payment_provider' => 'manual',
        ]);
        $this->assertSame(1, $this->service()->usedToday($this->company, 'manual'));
    }

    public function test_uninitiated_pending_tranche_does_not_count_toward_daily_cap(): void
    {
        // A pending tranche awaiting collection (never charged: no provider
        // payment id) must not consume the daily allowance.
        $this->company->payments()->create([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 25_000_000,
            'currency' => 'KES',
            'status' => PaymentStatus::PENDING,
            'payment_provider' => 'manual',
            'provider_payment_id' => null,
        ]);

        $this->assertSame(0, $this->service()->usedToday($this->company, 'manual'));

        // An initiated pending tranche (charged) does count.
        $this->company->payments()->create([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 25_000_000,
            'currency' => 'KES',
            'status' => PaymentStatus::PENDING,
            'payment_provider' => 'manual',
            'provider_payment_id' => 'manual_initiated',
        ]);

        $this->assertSame(1, $this->service()->usedToday($this->company, 'manual'));
    }

    public function test_failed_first_tranche_fails_the_whole_group(): void
    {
        // A driver that always rejects the charge.
        $manager = app(PaymentManager::class);
        $manager->extend('always_fail', fn (): PaymentProviderInterface => new class implements PaymentProviderInterface
        {
            public function charge(int $amount, string $currency, array $options = []): array
            {
                return [
                    'success' => false,
                    'provider_payment_id' => null,
                    'provider_reference' => null,
                    'status' => 'failed',
                    'metadata' => ['error' => 'rejected'],
                ];
            }

            public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array
            {
                return ['success' => false, 'provider_refund_id' => null, 'status' => 'failed', 'metadata' => []];
            }

            public function getPaymentStatus(string $providerPaymentId): string
            {
                return 'failed';
            }

            public function verifyWebhook(Request $request): bool
            {
                return false;
            }

            public function parseWebhook(Request $request): array
            {
                return ['event' => 'payment.failed', 'provider_payment_id' => null, 'status' => 'failed', 'amount' => null, 'currency' => null, 'metadata' => []];
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'always_fail';
            }
        });

        $this->setConfig('billing.providers.always_fail.limits', ['max_amount' => 25_000_000]);

        $result = $this->service()->process($this->company, 'always_fail', 50_000_000, 'KES');

        $this->assertFalse($result['success']);

        // No tranche is left pending/collectible: the whole group is failed.
        $statuses = Payment::query()
            ->where('payment_group', $result['group'])
            ->pluck('status')
            ->all();

        $this->assertNotEmpty($statuses);
        foreach ($statuses as $status) {
            $this->assertSame(PaymentStatus::FAILED, $status);
        }
    }

    // ─── Invoice settlement ────────────────────────────────────────────

    public function test_invoice_moves_to_partially_paid_then_paid(): void
    {
        $plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Big', 'slug' => 'big', 'base_price' => 50_000_000,
            'billing_cycle' => BillingCycle::MONTHLY, 'is_active' => true, 'sort_order' => 1,
            'features' => [], 'limits' => [],
        ]);

        $invoice = Invoice::query()->create([
            'ulid' => Str::ulid()->toBase32(),
            'billable_type' => Company::class,
            'billable_id' => $this->company->id,
            'number' => 'INV-SPLIT-1',
            'status' => InvoiceStatus::SENT,
            'subtotal' => 50_000_000,
            'tax_amount' => 0,
            'total' => 50_000_000,
            'currency' => 'KES',
            'tax_rate' => 0,
        ]);

        $makeTranche = fn (int $seq): Payment => $this->company->payments()->create([
            'ulid' => Str::ulid()->toBase32(),
            'invoice_id' => $invoice->id,
            'amount' => 25_000_000,
            'currency' => 'KES',
            'status' => PaymentStatus::COMPLETED,
            'payment_provider' => 'manual',
            'payment_group' => 'grp',
            'group_sequence' => $seq,
            'group_size' => 2,
        ]);

        $makeTranche(1);
        $invoice->recalculateStatus();
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, $invoice->status);

        $makeTranche(2);
        $invoice->recalculateStatus();
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    // ─── API ───────────────────────────────────────────────────────────

    public function test_api_splits_payment_over_limit(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments', ['amount' => 50_000_000]);

        $response->assertCreated()
            ->assertJsonPath('meta.split', true)
            ->assertJsonPath('meta.tranche_count', 2)
            ->assertJsonPath('meta.total_amount', 50_000_000)
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('billing_payments', 2);
    }

    public function test_api_single_payment_keeps_legacy_shape(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments', ['amount' => 20_000_000]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', 20_000_000)
            ->assertJsonMissingPath('meta.split');

        $this->assertDatabaseCount('billing_payments', 1);
    }

    public function test_api_rejects_amount_exceeding_daily_cap(): void
    {
        $this->setConfig('billing.providers.manual.limits', ['max_amount' => 25_000_000, 'max_per_day' => 2]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/billing/payments', ['amount' => 75_000_000]); // needs 3 tranches

        $response->assertStatus(422)
            ->assertJsonPath('tranches_needed', 3)
            ->assertJsonPath('tranches_allowed', 2);

        $this->assertDatabaseCount('billing_payments', 0);
    }

    public function test_api_collect_pending_tranche(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/billing/payments', ['amount' => 50_000_000])
            ->assertCreated();

        $tranche2 = Payment::query()->where('group_sequence', 2)->firstOrFail();
        $this->assertNull($tranche2->provider_payment_id);

        $response = $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$tranche2->id}/collect");

        $response->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertNotNull($tranche2->refresh()->provider_payment_id);
    }

    public function test_api_collect_rejects_non_pending_tranche(): void
    {
        $payment = $this->company->payments()->create([
            'ulid' => Str::ulid()->toBase32(),
            'amount' => 25_000_000,
            'currency' => 'KES',
            'status' => PaymentStatus::COMPLETED,
            'payment_provider' => 'manual',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/billing/payments/{$payment->id}/collect")
            ->assertStatus(422);
    }
}
