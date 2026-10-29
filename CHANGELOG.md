# Unreleased
* a subscription that is past due or expired is restored when its invoice is paid by any route that does not charge through the renewal job: a paybill payment, a verified proof of payment, `mark-paid`, or an allocated stray payment. A paid cycle runs from the payment (or keeps the period that still runs), a cancelled subscription is never brought back, and one a provider bills itself is left to the provider (`SubscriptionRecovery`)
* keep a paybill payment that names no usable invoice as a record (`billing_unmatched_payments`, one per M-Pesa receipt, so a redelivery adds nothing) instead of only an event and a log line. Back-office routes under `admin/unmatched-payments` list them, allocate one to an open invoice of the same currency (a completed M-Pesa payment, the invoice settles, an over payment is flagged) or dismiss it with a reason. Run `php artisan migrate`
* fix renewals that charge a saved card: `ProcessRenewals` and `ProcessDunning` now charge the customer's saved reusable card on Paystack (email plus authorization code, with a reference fixed to the period so a retried job cannot bill it twice), and a subscription with no usable saved card goes past due without anything being sent. Dunning no longer touches subscriptions Paystack bills itself. Before this the job sent no email or card and could not charge anyone
* save the reusable card from a completed Paystack payment as a `PaymentToken` (once per card, default when it is the first), through `SavedCardCharger`
* match a customer's own paybill payment to an invoice: the account number is matched to the invoice number (case, spaces and dashes ignored), recorded against it and settled, so the invoice updates and `PaymentReceived` fires. Over payments are kept and flagged (`overpaid_by`), void and draft invoices are never matched, and a payment naming no usable invoice fires `PaybillPaymentUnmatched` instead of being dropped. Turn off with `billing.providers.mpesa.match_invoices` (`MPESA_MATCH_INVOICES`)
* Paystack subscriptions are card only: `start()` limits the checkout to cards, a first payment that arrives by another channel creates a subscription the package renews itself (`billing_mode` `self`) instead of one left waiting for Paystack, and a Paystack billed subscription whose renewal is not reported within `billing.paystack.overdue_grace_days` (default 3) goes past due. `PaystackProvider::initializeTransaction()` accepts `channels`
* M-Pesa Ratiba debits on a paybill: a paybill has one C2B confirmation URL, so a standing order debit arriving at `{webhooks.prefix}/mpesa` is now recorded against its order (matched by account reference, verified requests only) instead of being dropped as an unknown payment. Other C2B payments settle as before
* M-Pesa Ratiba on a paybill: read the response and callback in the shapes Safaricom documents (`responseHeader`, `ResponseBody.ResponseData.TransactionID`/`Status`) as well as the shapes read before, send `Amount` as a string as documented, and use the documented Till transaction type spelling (`Standing Order Customer Pay Marchant`; override with `transaction_types.till` if Safaricom confirms another)
* add Paystack card subscriptions that Paystack bills itself: `billing:sync-plans --paystack` creates or updates each active plan on Paystack (price or cycle changes are applied to new subscriptions only unless `--update-existing`), `PaystackSubscriptionService::start()` sends the customer to Paystack to pay the first cycle, and Paystack's `subscription.create`, `charge.success` (per cycle), `invoice.payment_failed`, `subscription.not_renew` and `subscription.disable` webhooks update the local subscription (period, Payment, `SubscriptionCreated`/`SubscriptionRenewed`/`PaymentFailed`/`SubscriptionCancelled`/`SubscriptionExpired`)
* `ProcessRenewals` skips a subscription Paystack bills, and `Subscription::cancel()` stops Paystack billing it, so a customer is never charged twice or after cancelling
* Paystack recurs cards (and direct debit in Nigeria) only: M-Pesa keeps using Ratiba standing orders, and both feed the same `Subscription`
* `PaystackProvider::initializeTransaction()` accepts a `plan` option; new `createPlan`, `updatePlan`, `createSubscription`, `fetchSubscription`, `disableSubscription`, `enableSubscription` and `subscriptionManageLink`

# v0.1.4 (Oct 28, 2026)
* BREAKING: PayOrchestra driver callback verification follows the backbone's new contract. The signature is the HMAC-SHA256 of `{X-PayOrchestra-Timestamp}.{raw body}` and the timestamp must be within five minutes, so a captured delivery cannot be replayed. A body-only signature, or a callback with no timestamp, is refused. Use this release with a backbone that sends `X-PayOrchestra-Timestamp`; older packages cannot verify its callbacks
* PayOrchestra driver: accept `X-PayOrchestra-Signature-Previous` during a webhook secret rotation, so a rotation does not drop callbacks

# v0.1.3 (Oct 23, 2026)
* PayOrchestra driver: card charges ask the backbone to wait for the provider's checkout page and return it as `metadata.authorization_url` (and `checkout_url`), so callers that redirect on that key work unchanged; `redirect_url` becomes the provider's return page; a payment the backbone fails outright now returns `success => false`
* PayOrchestra driver: `createHostedSession` now sends `callback_url` (option, or the package webhook route), so the product is told when a hosted payment finishes
* PayOrchestra driver: accept an `authorization_ref` charge option to bill a saved card without the payer present (Paystack, via the backbone); the reusable authorization comes back in the completed webhook as `metadata.reusable_authorization`

# v0.1.2 (Oct 21, 2026)
* add `PaystackSplitApi` (bound as a singleton): list banks, create/update/fetch subaccounts, create/update/fetch splits, add/update/remove split shares; failures throw `PaystackException` with Paystack's message
* add a default split for Paystack charges: `billing.providers.paystack.split_code` (+ `split_currency`) or `PaystackProvider::resolveDefaultSplitUsing()`; charges carrying their own split options are untouched
* add M-Pesa Ratiba standing orders: `SupportsStandingOrders` contract implemented by `MpesaProvider`, `StandingOrderFrequency`/`StandingOrderStatus` enums, `StandingOrder` and `StandingOrderExecution` models, `StandingOrderService`, `POST {webhooks.prefix}/mpesa/ratiba` callback route, `billing:reconcile-standing-orders` command and `StandingOrder*` events
* add `billing_standing_orders` and `billing_standing_order_executions` tables; run `php artisan migrate`
* off by default (`MPESA_RATIBA_ENABLED`); cancel/status endpoints must be configured once Safaricom provides them

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
