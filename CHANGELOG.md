# v0.1.0 (Jun 30, 2026)
* add runtime settings: per-tenant/global config overrides backed by `billing_settings`, no redeploy
* add proof of payment: record off-platform payment evidence for any channel, verify-to-settle, with events any maker-checker can hook
* add Paystack subaccounts and split-payment passthrough (server-controlled; stripped from payer input)
* BREAKING: webhook verification is now fail-closed; unsigned callbacks are confirmed via an async provider status re-query before settling, so forged callbacks settle nothing
* add per-provider webhook IP allowlist and shared-secret options
* BREAKING: scope invoice/subscription/add-on endpoints to the billable (fix cross-tenant access)
* BREAKING: gate plan/feature/coupon management and invoice void/mark-paid behind a billing-admin check
* cap refunds to the captured amount with partial-refund tracking
* serialize coupon redemptions and clamp discounts to prevent over-redemption
* make renewal/dunning/trial jobs idempotent to prevent double charges
* charge subscriptions in the plan currency; only settle invoices with same-currency payments
* clamp per-tenant provider limit overrides so they can only tighten caps
* redact secrets/PII in logs; default PII encryption at rest on
* BREAKING: `encrypt_at_rest` defaults on, and a partial refund keeps the payment completed (only a full refund marks it refunded)
* add `billing_settings` and `billing_payment_proofs` tables and a unique `provider_payment_id`; run `php artisan migrate`
* link official provider API docs and document the webhook security model in the README

# v0.0.3 (Mar 29, 2026)
* update to Laravel 13

# v0.0.2 (Mar 28, 2026)
* add payments

# v0.0.1 (Mar 22, 2026)
* add payments
