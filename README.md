# Moffhub Billing

Feature-based subscription billing for Laravel with first-class African payment provider support.

Define plans, gate features, track usage, accept payments via PayOrchestra (orchestration backbone with smart routing, failover, and reconciliation) or directly through M-Pesa, Airtel Money, T-Kash, KCB, Equity, Co-op Bank, Stanbic, NCBA, IntaSend, Paystack, Flutterwave, or Pesapal — all through one package.

---

## Why This Package?

Laravel Cashier is Stripe-only. Spark is closed-source. Neither handles M-Pesa or feature-based gating.

This package gives you:

- **Feature gating** — gate routes by feature slug, not plan name. Plans are just bundles of features.
- **Usage metering** — track and enforce limits on metered features (API calls, OCR scans, entries/month)
- **Provider-agnostic payments** — PayOrchestra orchestration backbone, plus 13 standalone drivers: M-Pesa, Airtel Money, T-Kash, KCB BUNI, Equity Jenga, Co-op Bank, Stanbic, NCBA, IntaSend, Paystack, Flutterwave, Pesapal, or manual/cash — 14 providers behind one interface
- **Multi-channel checkout** — offer a curated set of providers (`BILLING_ENABLED_PROVIDERS`) and let customers pick at checkout via `GET /payments/options`
- **Automatic payment splitting** — amounts above a provider's per-transaction limit (e.g. M-Pesa 250k) are split into linked tranche payments that settle one invoice, respecting per-day caps
- **Subscription lifecycle** — trials, renewals, cancellation, pause/resume, plan upgrades with proration
- **Invoicing** — auto-generated invoices with line items, tax calculation, sequential numbering
- **Full REST API** — 32 endpoints for managing plans, subscriptions, usage, payments, and invoices
- **Event-driven** — every state change fires a Laravel event for integration with SMS, email, webhooks

## Quick Start

### 1. Install

```bash
composer require moffhub/billing
```

### 2. Publish & Migrate

```bash
php artisan vendor:publish --tag=billing-config
php artisan vendor:publish --tag=billing-migrations
php artisan migrate
```

### 3. Add the Billable Trait

Add `Billable` to whichever model represents your paying entity — User, Team, Company, Organization:

```php
use Moffhub\Billing\Traits\Billable;

class Company extends Model
{
    use Billable;
}
```

### 4. Seed Plans & Features

```bash
php artisan billing:sync-plans --seed
```

Or create them programmatically:

```php
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Feature;

// Register features
Feature::create([
    'slug' => 'ocr_scanning',
    'name' => 'OCR Scanning',
    'type' => 'metered',       // boolean | metered | consumable
    'is_addon' => true,
    'addon_price' => 2000,     // KES 20.00 (in cents)
]);

// Create a plan
Plan::create([
    'ulid' => Str::ulid()->toBase32(),
    'name' => 'Standard',
    'slug' => 'standard',
    'base_price' => 750000,     // KES 7,500.00
    'billing_cycle' => 'monthly',
    'trial_days' => 14,
    'features' => ['gatebook', 'incidents', 'shifts', 'analytics_reports'],
    'limits' => [
        'max_posts' => 10,
        'max_guards' => 25,
        'ocr_scanning' => 100,
    ],
]);
```

### 5. Subscribe

```php
// Subscribe with a 14-day trial
$company->subscribe('standard')
    ->trialDays(14)
    ->provider('mpesa')
    ->create();

// Check subscription status
$company->subscribed();          // true
$company->onPlan('standard');    // true
$company->onTrial();             // true
```

### 6. Gate Features

**Middleware** — protect routes by feature or plan:

```php
// Require a specific feature (AND logic)
Route::middleware(['feature:ocr_scanning'])->group(function () {
    Route::post('ocr/scan', ScanController::class);
});

// Require one of several plans (OR logic)
Route::middleware(['plan:professional,enterprise'])->group(function () {
    Route::get('analytics/advanced', AnalyticsController::class);
});

// Combine feature access + usage limit enforcement
Route::middleware(['feature:ocr_scanning', 'usage:ocr_scanning'])->group(function () {
    Route::post('ocr/scan', ScanController::class);
});
```

**Blade** — show/hide UI elements:

```blade
@feature('shifts')
    <a href="/shifts">Shift Management</a>
@else
    <a href="/upgrade">Upgrade to unlock Shifts</a>
@endfeature

@plan('professional')
    <span class="badge">PRO</span>
@endplan
```

**In code** — check programmatically:

```php
if ($company->hasFeature('ocr_scanning')) {
    // perform scan
}

$remaining = $company->remainingQuota('ocr_scanning'); // 58
$percentage = $company->usagePercentage('ocr_scanning'); // 0.42
```

### 7. Track Usage

```php
use Moffhub\Billing\Services\UsageService;

$usage = app(UsageService::class);

// Record a usage event
$usage->record($company, 'ocr_scanning');

// Record with quantity and deduplication
$usage->record($company, 'ocr_scanning', quantity: 5, transactionId: 'scan-abc-123');

// Check limits
$usage->isWithinLimit($company, 'ocr_scanning'); // true/false
$usage->getUsage($company, 'ocr_scanning');      // 42
```

Usage alerts fire automatically at configurable thresholds (80%, 90%, 100%).

### 8. Accept Payments

```php
use Moffhub\Billing\PaymentManager;

$manager = app(PaymentManager::class);

// Charge via the default provider
$result = $manager->driver()->charge(750000, 'KES', [
    'phone' => '254712345678',    // for M-Pesa STK Push
]);

// Or specify a provider
$result = $manager->driver('paystack')->charge(750000, 'KES', [
    'email' => 'customer@example.com',
]);

// Collect via KCB BUNI (M-Pesa, Airtel, T-Kash, VOOMA, or bank)
$result = $manager->driver('kcb')->charge(50000, 'KES', [
    'phone' => '0712345678',
    'payment_channel' => 'mpesa',  // mpesa, airtel, tkash, vooma, bank
    'reference' => 'INV-001',
]);

// Collect via Airtel Money
$result = $manager->driver('airtel')->charge(50000, 'KES', [
    'phone' => '0733123456',
    'reference' => 'INV-002',
]);

// Transfer via Co-op Bank PesaLink (to any Kenyan bank)
$result = $manager->driver('coopbank')->charge(100000, 'KES', [
    'destination_account' => '0011547896523',
    'bank_code' => '01',           // KCB
    'transfer_type' => 'pesalink',
]);

// Route through PayOrchestra — channel hint picks the right connector
$result = $company->chargeVia('mpesa', 750000, 'KES', [
    'phone' => '254712345678',
    'reference' => 'INV-001',
]);

$result = $company->chargeVia('card', 750000, 'KES', [
    'email' => 'customer@example.com',
    'reference' => 'INV-001',
]);

// PayOrchestra hosted checkout — redirect the payer
$session = $company->hostedPayment(750000, 'KES', [
    'description' => 'Invoice #INV-001',
    'success_url' => 'https://app.example.com/payment/success',
    'cancel_url' => 'https://app.example.com/payment/cancel',
]);

return redirect($session['session_url']);
```

### 9. Generate Invoices

```php
use Moffhub\Billing\Models\Invoice;

// Via API
POST /api/billing/invoices
{
    "items": [
        {"description": "Standard Plan - March 2026", "unit_price": 750000},
        {"description": "OCR Add-on - 42 scans",     "unit_price": 2100, "feature_slug": "ocr_scanning"}
    ],
    "tax_rate": 16.0
}

// Tax is calculated automatically (VAT 16% default for Kenya)
```

---

## API

The package ships with a full REST API. All endpoints are documented in [docs/API.md](docs/API.md).

| Resource | Endpoints | Description |
|----------|-----------|-------------|
| Plans | 5 | List, show, create, update, delete |
| Features | 6 | List, list add-ons, show, create, update, delete |
| Subscriptions | 8 | Subscribe, current, change plan, cancel, pause, resume |
| Add-ons | 3 | List, enable, disable |
| Usage | 3 | Summary, detail, record |
| Payments | 4 | List, initiate, show, refund |
| Invoices | 6 | List, create, show, send, void, mark-paid |
| Webhooks | 13 | M-Pesa, Paystack, Flutterwave, Pesapal, Airtel, T-Kash, KCB, Jenga, Co-op, Stanbic, NCBA, IntaSend, PayOrchestra callbacks |

Routes are configurable:

```php
// config/billing.php
'routes' => [
    'enabled' => true,
    'prefix' => 'api/billing',
    'middleware' => ['api', 'auth:sanctum'],
    'rate_limit' => 60,
],
```

---

## Payment Providers

| Provider | Driver | Payment Methods |
|----------|--------|-----------------|
| **PayOrchestra** ⭐ | `payorchestra` | Multi-channel via backbone — routes to M-Pesa, cards, bank transfers, etc. with smart routing, failover, reconciliation, hosted checkout |
| **M-Pesa** | `mpesa` | STK Push, C2B, B2C |
| **Airtel Money** | `airtel` | C2B collections, B2C disbursements |
| **T-Kash** | `tkash` | Telkom Kenya mobile money — collections, B2C disbursements |
| **KCB BUNI** | `kcb` | M-Pesa, Airtel, T-Kash, VOOMA, bank (multi-channel) |
| **Equity Jenga** | `jenga` | Cards, mobile money, bank transfers |
| **Co-op Bank** | `coopbank` | PesaLink (any bank), internal transfers, balance queries |
| **Stanbic Bank** | `stanbic` | STK Push, mobile money, bank transfers |
| **NCBA** | `ncba` | PesaLink, IPN Push |
| **IntaSend** | `intasend` | M-Pesa STK Push, cards, bank, PesaLink |
| **Paystack** | `paystack` | Cards, bank, mobile money |
| **Flutterwave** | `flutterwave` | Cards, M-Pesa, MTN MoMo, bank |
| **Pesapal** | `pesapal` | Cards, M-Pesa, Airtel Money |
| **Manual** | `manual` | Cash, bank transfer, cheque |

Providers without direct APIs (Telkom T-Kash, Family Bank, DTB) can be accessed through KCB BUNI, Pesapal, or Flutterwave. See [docs/PROVIDERS.md](docs/PROVIDERS.md) for full integration guides.

All providers implement `PaymentProviderInterface`:

```php
interface PaymentProviderInterface
{
    public function charge(int $amount, string $currency, array $options = []): array;
    public function refund(string $providerPaymentId, ?int $amount = null, array $options = []): array;
    public function getPaymentStatus(string $providerPaymentId): string;
    public function verifyWebhook(Request $request): bool;
    public function parseWebhook(Request $request): array;
    public function isConfigured(): bool;
    public function getName(): string;
}
```

Add your own provider:

```php
class MyProvider extends BasePaymentProvider
{
    public function charge(int $amount, string $currency, array $options = []): array
    {
        // Your implementation
        return ['success' => true, 'provider_payment_id' => '...', 'status' => 'completed', ...];
    }

    public function getName(): string { return 'my-provider'; }
    public function isConfigured(): bool { return true; }
}
```

---

## Events

Every state change fires a Laravel event so you can integrate with your SMS, email, analytics, or approval workflows.

| Event | Fired When | Payload |
|-------|-----------|---------|
| `SubscriptionCreated` | New subscription | `$subscription` |
| `SubscriptionCancelled` | Subscription cancelled | `$subscription`, `$immediately` |
| `SubscriptionRenewed` | Subscription renewed | `$subscription` |
| `PlanChanged` | Plan upgrade/downgrade | `$subscription`, `$oldPlan`, `$newPlan` |
| `PaymentReceived` | Successful payment | `$payment` |
| `PaymentFailed` | Failed payment | `$payment`, `$reason` |
| `UsageLimitApproaching` | Usage hits 80/90/100% | `$billable`, `$featureSlug`, `$percentage` |
| `FeatureAccessDenied` | Unauthorized feature access | `$billable`, `$featureSlug`, `$reason` |

Example listener:

```php
// In EventServiceProvider
protected $listen = [
    PaymentReceived::class => [SendPaymentReceiptSms::class],
    UsageLimitApproaching::class => [SendUsageWarningNotification::class],
];
```

---

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=billing-config
```

Key settings:

```php
return [
    'currency' => 'KES',                    // Default currency (ISO 4217)
    'default_provider' => 'mpesa',           // Default payment provider
    'billable_model' => App\Models\Company::class,

    'tax' => [
        'enabled' => true,
        'default_rate' => 16.0,              // Kenya VAT
    ],

    'features' => [
        'cache_ttl' => 300,                  // Feature access cache (seconds)
    ],

    'usage' => [
        'alert_thresholds' => [80, 90, 100], // Fire events at these %
        'allow_overage' => false,            // Hard-stop or allow over-limit
    ],

    'subscriptions' => [
        'grace_period_days' => 7,            // Days before cancelling past-due
        'dunning_schedule' => [1, 3, 7],     // Retry failed charges on these days
        'allow_pause' => true,
    ],

    'providers' => [
        'payorchestra' => [
            'base_url' => env('PAYORCHESTRA_URL', 'https://backbone.payorchestra.com'),
            'api_key' => env('PAYORCHESTRA_API_KEY'),
            'org_id' => env('PAYORCHESTRA_ORG_ID'),
            'webhook_secret' => env('PAYORCHESTRA_WEBHOOK_SECRET'),
        ],
        'mpesa' => [
            'consumer_key' => env('MPESA_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
            'shortcode' => env('MPESA_SHORTCODE'),
            // ...
        ],
        // airtel, kcb, jenga, coopbank, stanbic, ncba, intasend,
        // paystack, flutterwave, pesapal, manual
    ],
];
```

---

## Artisan Commands

```bash
# Seed default plans and features
php artisan billing:sync-plans --seed

# List current plans and features
php artisan billing:sync-plans

# Health check — providers, subscriptions, config
php artisan billing:health
```

---

## Architecture

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full code map, entity relationships, data flow diagrams, and event map.

Key design decisions:

- **Polymorphic billable** — `Billable` trait works on any Eloquent model via `morphMany`. Your billing entity can be User, Team, Company, or Organization.
- **Feature slugs over plan names** — gate access by feature (`hasFeature('shifts')`) not plan (`onPlan('professional')`). This means plan restructuring doesn't break your code.
- **JSON features/limits on Plan** — features are stored as a JSON array on the plan, limits as a JSON object. No pivot tables, no joins. Fast reads, easy to reason about.
- **Usage deduplication** — `transaction_id` on usage events prevents double-counting (idempotent recording).
- **Provider Manager pattern** — `PaymentManager` extends Laravel's `Manager` class. Same driver pattern as `Mail`, `Queue`, `Cache`. Add providers without changing core code.
- **Event-driven integration** — the package fires events, never imports other packages. SMS, approvals, and USSD integration happen via listeners in your app.

---

## Testing

```bash
cd packages/moffhub/billing
composer install
vendor/bin/phpunit
```

**716 tests, 1,840 assertions** covering:
- Plan CRUD, feature checking, limits
- Subscription lifecycle (create, trial, cancel, pause/resume)
- Feature gating via Billable trait
- Usage recording, deduplication, limit enforcement
- All 13 payment providers (charge, refund, status, webhooks) including PayOrchestra orchestration
- Invoice generation, tax calculation, proration
- Coupon and promotion code logic
- Admin bypass, encryption, event dispatching

---

## Ecosystem

This package is part of the Moffhub Laravel package suite:

| Package | Purpose | Integration |
|---------|---------|-------------|
| [moffhub/billing](.) | Subscriptions, feature gating, payments | Core |
| [moffhub/maker-checker](../maker-checker) | Approval workflows | Gate high-value billing actions (refunds, plan changes) |
| [moffhub/sms-handler](../sms-handler) | Multi-provider SMS | Send payment receipts, usage warnings, invoice reminders |
| [moffhub/ussd](../ussd) | USSD menus | "Check balance", "Upgrade plan" via USSD |

The packages are fully decoupled — billing fires events, your app wires them to SMS, approvals, or USSD as needed.

---

## Requirements

- PHP 8.3+
- Laravel 12.0+
- A database (MySQL, PostgreSQL, SQLite)

---

## License

MIT
