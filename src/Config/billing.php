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
    | Supported: "mpesa", "paystack", "flutterwave", "pesapal", "airtel",
    |           "kcb", "jenga", "coopbank", "stanbic", "ncba", "intasend", "manual"
    |
    */
    'default_provider' => env('BILLING_PROVIDER', 'mpesa'),

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
    | Payment Providers
    |--------------------------------------------------------------------------
    */
    'providers' => [

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
        // Encrypt PII fields at rest (phone, email, token on PaymentToken, etc.)
        'encrypt_at_rest' => env('BILLING_ENCRYPT_AT_REST', false),

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
    ],
];
