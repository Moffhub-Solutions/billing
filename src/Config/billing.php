<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default currency for all billing operations. Uses ISO 4217 codes.
    |
    */
    'currency' => env('BILLING_CURRENCY', 'KES'),

    /*
    |--------------------------------------------------------------------------
    | Default Payment Provider
    |--------------------------------------------------------------------------
    |
    | The default payment provider to use when none is specified.
    |
    | Recommended: "payorchestra" — routes through the PayOrchestra backbone
    | for multi-channel payments, smart routing, failover, and reconciliation.
    |
    | Standalone drivers (direct gateway integrations):
    |   "mpesa", "paystack", "flutterwave", "pesapal", "airtel", "tkash",
    |   "kcb", "jenga", "coopbank", "stanbic", "ncba", "intasend", "manual"
    |
    */
    'default_provider' => env('BILLING_PROVIDER', 'mpesa'),

    /*
    |--------------------------------------------------------------------------
    | Enabled Payment Providers
    |--------------------------------------------------------------------------
    |
    | The subset of providers offered to customers at checkout. The frontend
    | reads these (via GET {prefix}/payments/options) to render a multi-option
    | payment selector, and a charge is only accepted for a provider in this
    | list. Order is preserved for display.
    |
    | Set a comma-separated list, e.g. BILLING_ENABLED_PROVIDERS=mpesa,airtel,tkash
    | Leave empty to offer every configured provider automatically.
    |
    */
    'enabled_providers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BILLING_ENABLED_PROVIDERS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Offer Cash (Manual) at Checkout
    |--------------------------------------------------------------------------
    |
    | Cash / manual settlement is offered in the checkout payment options by
    | default. Set this to false to hide it from the customer-facing selector
    | (it remains usable for server-initiated and back-office charges). An
    | explicit "manual" entry in BILLING_ENABLED_PROVIDERS always overrides this.
    |
    */
    'offer_cash' => env('BILLING_OFFER_CASH', true),

    /*
    |--------------------------------------------------------------------------
    | Billable Model
    |--------------------------------------------------------------------------
    |
    | The model class that represents your billable entity. This is typically
    | your User, Team, Company, or Organization model.
    |
    */
    'billable_model' => env('BILLING_BILLABLE_MODEL', 'App\\Models\\Company'),

    /*
    |--------------------------------------------------------------------------
    | Billable Relation
    |--------------------------------------------------------------------------
    |
    | When the authenticated user is not the billable entity (e.g., User
    | belongs to Company, Company is billable), specify the relationship
    | method on the User model that returns the billable.
    |
    | Set to null if User is itself the billable.
    |
    */
    'billable_relation' => 'company',

    /*
    |--------------------------------------------------------------------------
    | Admin Bypass
    |--------------------------------------------------------------------------
    |
    | Allow admin users to bypass feature gating, plan access checks, and
    | usage limits. The package calls the method named here on the billable
    | (or authenticated user) to determine admin status.
    |
    | Set to null to disable admin bypass entirely.
    |
    | Examples: 'isAdmin', 'isSuperAdmin', 'hasFullAccess'
    |
    */
    'admin_bypass_method' => null,

    /*
    |--------------------------------------------------------------------------
    | Admin Gate
    |--------------------------------------------------------------------------
    |
    | Authorization for back-office routes (managing plans, features, coupons,
    | and voiding / marking invoices paid), enforced by the `billing.admin`
    | middleware. Set to a Laravel Gate ability name to use your own policy;
    | otherwise the billable's `isBillingAdmin()` (see admin_bypass_method) is
    | used. When neither grants access the routes fail closed (403).
    |
    */
    'admin_gate' => env('BILLING_ADMIN_GATE'),

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    */
    'invoices' => [
        'prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),
        'number_format' => '{prefix}-{year}-{sequence}', // e.g., INV-2026-0001
        'sequence_padding' => 4,
        'due_days' => 30,
        'company_name' => env('BILLING_COMPANY_NAME'),
        'company_address' => env('BILLING_COMPANY_ADDRESS'),
        'company_phone' => env('BILLING_COMPANY_PHONE'),
        'company_email' => env('BILLING_COMPANY_EMAIL'),
        'tax_pin' => env('BILLING_TAX_PIN'), // KRA PIN for Kenya
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Settings
    |--------------------------------------------------------------------------
    */
    'tax' => [
        'enabled' => env('BILLING_TAX_ENABLED', true),
        'calculator' => null, // null = use default, or FQCN implementing TaxCalculatorInterface
        'default_rate' => 16.0, // VAT rate for Kenya
        'label' => 'VAT',
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Gating
    |--------------------------------------------------------------------------
    */
    'features' => [
        // Cache resolved feature access for performance
        'cache_ttl' => env('BILLING_FEATURE_CACHE_TTL', 300), // seconds (5 minutes)
        'cache_prefix' => 'billing_features',

        // Where to resolve features from: "database", "config", or "both"
        'driver' => env('BILLING_FEATURES_DRIVER', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Tracking
    |--------------------------------------------------------------------------
    */
    'usage' => [
        // Alert thresholds (percentage of limit)
        'alert_thresholds' => [80, 90, 100],

        // Allow overage (continue tracking beyond limit) or hard-stop
        'allow_overage' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Settings (database-backed overrides)
    |--------------------------------------------------------------------------
    |
    | A subset of the values in this file is "runtime overridable": operators
    | (and individual billables/tenants) can change them at runtime through the
    | BillingSettings resolver, which stores overrides in the billing_settings
    | table. This avoids editing this file and re-running `php artisan
    | config:cache` (or redeploying) just to flip a tax rate or grace period.
    |
    | Resolution order for an overridable key:
    |   per-billable override -> global override -> the value in this file.
    |
    | Credentials, table names, route prefixes, and other deploy-time keys are
    | deliberately NOT overridable (see the allowlist on the resolver) so secrets
    | never live in this table and boot-time wiring stays stable.
    |
    */
    'settings' => [
        // Cache resolved overrides to avoid a query per read. Set to 0 to
        // disable caching (e.g. in tests).
        'cache_ttl' => env('BILLING_SETTINGS_CACHE_TTL', 300), // seconds

        'cache_prefix' => 'billing_settings',

        // Cache store to use. Null = the application default store.
        'cache_store' => env('BILLING_SETTINGS_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Settings
    |--------------------------------------------------------------------------
    */
    'subscriptions' => [
        // Grace period in days after payment failure before cancellation
        'grace_period_days' => env('BILLING_GRACE_PERIOD', 7),

        // Dunning retry schedule (days after initial failure)
        'dunning_schedule' => [1, 3, 7],

        // Allow pausing subscriptions
        'allow_pause' => true,

        // Proration on plan changes
        'prorate' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Split Payments (transaction limits)
    |--------------------------------------------------------------------------
    |
    | Providers cap how much a single transaction may carry (e.g. M-Pesa allows
    | 250,000 KES per STK Push). When a charge exceeds a provider's limit, the
    | package can split it into several tranche payments that share a payment
    | group and settle one invoice.
    |
    | All amounts are in cents (the unit `amount` is stored in), so 250,000 KES
    | is 25_000_000. Per-provider limits live under `providers.<name>.limits`:
    |
    |   'limits' => [
    |       'max_amount'  => 25_000_000, // max cents per transaction (null = no cap)
    |       'max_per_day' => 2,          // max transactions/day per billable (null = no cap)
    |   ],
    |
    | `auto_advance` controls sequential collection: when true, completing one
    | tranche (via webhook) initiates the next automatically. Set false to drive
    | tranche collection yourself via POST {prefix}/payments/{id}/collect.
    |
    */
    'split_payments' => [
        'enabled' => env('BILLING_SPLIT_PAYMENTS', true),
        'auto_advance' => env('BILLING_SPLIT_AUTO_ADVANCE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Providers
    |--------------------------------------------------------------------------
    */
    'providers' => [

        'payorchestra' => [
            'base_url' => env('PAYORCHESTRA_URL', 'https://backbone.payorchestra.com'),
            'api_key' => env('PAYORCHESTRA_API_KEY'),
            'org_id' => env('PAYORCHESTRA_ORG_ID'),
            'webhook_secret' => env('PAYORCHESTRA_WEBHOOK_SECRET'),
            'timeout' => (int) env('PAYORCHESTRA_TIMEOUT', 30),
        ],

        'mpesa' => [
            'consumer_key' => env('MPESA_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
            'shortcode' => env('MPESA_SHORTCODE'),
            'passkey' => env('MPESA_PASSKEY'),
            'environment' => env('MPESA_ENVIRONMENT', 'sandbox'), // sandbox or production
            'callback_url' => env('MPESA_CALLBACK_URL'),
            'timeout_url' => env('MPESA_TIMEOUT_URL'),
            'base_url' => env('MPESA_BASE_URL'), // auto-set based on environment if null
            // B2C (refunds/disbursements) — optional
            'initiator_name' => env('MPESA_INITIATOR_NAME'),
            'initiator_password' => env('MPESA_INITIATOR_PASSWORD'),
            'certificate_path' => env('MPESA_CERTIFICATE_PATH'), // path to Safaricom .cer file
            'limits' => [
                'max_amount' => (int) env('MPESA_MAX_AMOUNT', 25_000_000),     // 250,000 KES per STK Push
                'max_per_day' => env('MPESA_MAX_PER_DAY') !== null ? (int) env('MPESA_MAX_PER_DAY') : null,
            ],
        ],

        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        ],

        'flutterwave' => [
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET'),
            'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
        ],

        'pesapal' => [
            'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
            'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
            'environment' => env('PESAPAL_ENVIRONMENT', 'sandbox'),
            'callback_url' => env('PESAPAL_CALLBACK_URL'),
            'base_url' => env('PESAPAL_BASE_URL'), // auto-set based on environment if null
            'ipn_id' => env('PESAPAL_IPN_ID'), // from RegisterIPN — call once and store
        ],

        'airtel' => [
            'client_id' => env('AIRTEL_CLIENT_ID'),
            'client_secret' => env('AIRTEL_CLIENT_SECRET'),
            'environment' => env('AIRTEL_ENVIRONMENT', 'sandbox'), // sandbox or production
            'callback_url' => env('AIRTEL_CALLBACK_URL'),
            'base_url' => env('AIRTEL_BASE_URL'), // auto-set based on environment if null
            'country' => env('AIRTEL_COUNTRY', 'KE'),
            'currency' => env('AIRTEL_CURRENCY', 'KES'),
            'limits' => [
                'max_amount' => (int) env('AIRTEL_MAX_AMOUNT', 15_000_000),    // 150,000 KES per transaction
                'max_per_day' => env('AIRTEL_MAX_PER_DAY') !== null ? (int) env('AIRTEL_MAX_PER_DAY') : null,
            ],
        ],

        'tkash' => [
            'consumer_key' => env('TKASH_CONSUMER_KEY'),       // app consumer key (Basic auth)
            'consumer_secret' => env('TKASH_CONSUMER_SECRET'), // app consumer secret (Basic auth)
            'consumer_id' => env('TKASH_CONSUMER_ID'),         // merchant/paybill consumer id
            'grant_username' => env('TKASH_GRANT_USERNAME'),   // authorizes token generation
            'grant_password' => env('TKASH_GRANT_PASSWORD'),
            'b2c_username' => env('TKASH_B2C_USERNAME'),       // required for disbursements/refunds
            'b2c_password' => env('TKASH_B2C_PASSWORD'),
            'environment' => env('TKASH_ENVIRONMENT', 'sandbox'), // sandbox(uat), dev, preprod, production(prod)
            'callback_url' => env('TKASH_CALLBACK_URL'),       // C2B confirmation URL
            'validation_url' => env('TKASH_VALIDATION_URL'),   // C2B validation URL
            'base_url' => env('TKASH_BASE_URL'), // auto-set based on environment if null
            'currency' => env('TKASH_CURRENCY', 'KES'),
            'limits' => [
                'max_amount' => (int) env('TKASH_MAX_AMOUNT', 15_000_000),     // 150,000 KES per transaction
                'max_per_day' => env('TKASH_MAX_PER_DAY') !== null ? (int) env('TKASH_MAX_PER_DAY') : null,
            ],
        ],

        'kcb' => [
            'api_key' => env('KCB_API_KEY'),
            'api_secret' => env('KCB_API_SECRET'),
            'environment' => env('KCB_ENVIRONMENT', 'sandbox'), // sandbox or production
            'callback_url' => env('KCB_CALLBACK_URL'),
            'base_url' => env('KCB_BASE_URL'), // auto-set based on environment if null
            'merchant_code' => env('KCB_MERCHANT_CODE'),
        ],

        'jenga' => [
            'api_key' => env('JENGA_API_KEY'),
            'merchant_code' => env('JENGA_MERCHANT_CODE'),
            'consumer_secret' => env('JENGA_CONSUMER_SECRET'),
            'private_key_path' => env('JENGA_PRIVATE_KEY_PATH'), // path to PEM file for SHA-256 signing
            'environment' => env('JENGA_ENVIRONMENT', 'sandbox'), // sandbox or production
            'callback_url' => env('JENGA_CALLBACK_URL'),
            'base_url' => env('JENGA_BASE_URL'), // auto-set based on environment if null
        ],

        'coopbank' => [
            'consumer_key' => env('COOPBANK_CONSUMER_KEY'),
            'consumer_secret' => env('COOPBANK_CONSUMER_SECRET'),
            'account_number' => env('COOPBANK_ACCOUNT_NUMBER'),
            'environment' => env('COOPBANK_ENVIRONMENT', 'sandbox'), // sandbox or production
            'callback_url' => env('COOPBANK_CALLBACK_URL'),
            'base_url' => env('COOPBANK_BASE_URL'), // auto-set based on environment if null
        ],

        'stanbic' => [
            'api_key' => env('STANBIC_API_KEY'),
            'api_secret' => env('STANBIC_API_SECRET'),
            'environment' => env('STANBIC_ENVIRONMENT', 'sandbox'), // sandbox or production
            'callback_url' => env('STANBIC_CALLBACK_URL'),
            'base_url' => env('STANBIC_BASE_URL'), // auto-set based on environment if null
            'merchant_code' => env('STANBIC_MERCHANT_CODE'),
        ],

        'ncba' => [
            'api_key' => env('NCBA_API_KEY'),
            'api_secret' => env('NCBA_API_SECRET'),
            'environment' => env('NCBA_ENVIRONMENT', 'sandbox'), // sandbox or production
            'callback_url' => env('NCBA_CALLBACK_URL'),
            'base_url' => env('NCBA_BASE_URL'), // auto-set based on environment if null
        ],

        'intasend' => [
            'publishable_key' => env('INTASEND_PUBLISHABLE_KEY'),
            'secret_key' => env('INTASEND_SECRET_KEY'),
            'environment' => env('INTASEND_ENVIRONMENT', 'sandbox'), // sandbox or production
            'callback_url' => env('INTASEND_CALLBACK_URL'),
            'base_url' => env('INTASEND_BASE_URL'), // auto-set based on environment if null
        ],

        'manual' => [
            // No credentials needed — records offline/cash payments
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'enabled' => env('BILLING_WEBHOOKS_ENABLED', true),
        'prefix' => env('BILLING_WEBHOOKS_PREFIX', 'billing/webhooks'),
        'middleware' => [],
        'rate_limit' => env('BILLING_WEBHOOKS_RATE_LIMIT', 60),

        /*
        | When a webhook is NOT cryptographically verified (the provider has no
        | signature, or none/secret is configured), do not trust the payload to
        | settle a payment. Instead enqueue an async job that re-queries the
        | provider's own status API and settles only on a confirmed result, so a
        | forged callback settles nothing. Strongly recommended on.
        */
        'confirm_unverified' => env('BILLING_WEBHOOKS_CONFIRM_UNVERIFIED', true),

        // Async re-query retry schedule (seconds) for callbacks that arrive
        // before the provider's status API is consistent. After the last delay
        // the job gives up and leaves the payment for the next callback/cron.
        'confirm_backoff' => [30, 120, 600],

        /*
        | Per-provider edge controls (all optional):
        |   'secret'       => shared token required on the callback (sent as
        |                     ?secret= on the registered URL or X-Webhook-Secret
        |                     header). Good for new integrations without a native
        |                     signature; verified requests take the fast path.
        |   'ip_allowlist' => IPs/CIDRs the provider posts from. When set, a
        |                     request from any other IP is rejected outright.
        |
        | Example:
        |   'mpesa' => [
        |       'secret' => env('MPESA_WEBHOOK_SECRET'),
        |       'ip_allowlist' => ['196.201.214.0/24', '196.201.213.0/24'],
        |   ],
        */
        'providers' => [
            //
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled' => env('BILLING_ROUTES_ENABLED', true),
        'prefix' => env('BILLING_ROUTES_PREFIX', 'api/billing'),
        'middleware' => ['api'],
        'rate_limit' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security & Encryption
    |--------------------------------------------------------------------------
    |
    | PII (phone numbers, emails, payment tokens) is encrypted at rest in the
    | database using Laravel's APP_KEY when enabled. Enable in production.
    | Transit encryption is handled by HTTPS at the infrastructure level.
    |
    */
    'security' => [
        // Encrypt PII fields at rest (phone, email, token on PaymentToken, etc.).
        // On by default: payment tokens and customer PII should not sit in
        // plaintext. Uses the app's APP_KEY, so keep that key stable/backed up.
        'encrypt_at_rest' => env('BILLING_ENCRYPT_AT_REST', true),

        // Fields to encrypt when using FieldEncryptor on arrays (e.g., webhook payloads)
        'encrypted_fields' => [
            'phone',
            'email',
            'card_exp_month',
            'card_exp_year',
            'token',
        ],

        // Keys to redact before logging (case-insensitive)
        'scrub_keys' => [
            'token', 'secret', 'password', 'api_key', 'consumer_secret',
            'auth_token', 'passkey', 'card_number', 'cvv', 'pin',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | USSD Settings
    |--------------------------------------------------------------------------
    |
    | Configure USSD billing access for feature-phone and low-bandwidth users.
    | The gateway receives callbacks from providers like Africa's Talking.
    |
    */
    'ussd' => [
        'enabled' => env('BILLING_USSD_ENABLED', false),
        'service_code' => env('BILLING_USSD_SERVICE_CODE', '*384*123#'),
        'session_ttl' => 300, // 5 minutes
        'gateway' => env('BILLING_USSD_GATEWAY', 'africastalking'), // africastalking, hubtel
        'phone_field' => env('BILLING_USSD_PHONE_FIELD', 'phone'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Customize the database table names used by the billing package.
    |
    */
    'tables' => [
        'plans' => 'billing_plans',
        'features' => 'billing_features',
        'subscriptions' => 'billing_subscriptions',
        'subscription_addons' => 'billing_subscription_addons',
        'usage_records' => 'billing_usage_records',
        'usage_events' => 'billing_usage_events',
        'payments' => 'billing_payments',
        'invoices' => 'billing_invoices',
        'invoice_items' => 'billing_invoice_items',
        'coupons' => 'billing_coupons',
        'promotion_codes' => 'billing_promotion_codes',
        'coupon_redemptions' => 'billing_coupon_redemptions',
        'payment_tokens' => 'billing_payment_tokens',
        'settings' => 'billing_settings',
        'payment_proofs' => 'billing_payment_proofs',
    ],
];
