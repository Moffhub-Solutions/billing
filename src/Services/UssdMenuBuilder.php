<?php

declare(strict_types=1);

namespace Moffhub\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Moffhub\Billing\Contracts\BillableInterface;
use Moffhub\Billing\Contracts\PaymentProviderInterface;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\PaymentManager;

class UssdMenuBuilder
{
    public function __construct(
        protected UssdSessionManager $sessionManager,
        protected PaymentManager $paymentManager,
    ) {}

    /**
     * Process USSD input and return the appropriate response.
     *
     * @return array{response: string, is_terminal: bool}
     */
    public function handle(string $sessionId, string $phoneNumber, string $text): array
    {
        $parts = $text === '' ? [] : explode('*', $text);
        $billable = $this->resolveBillable($phoneNumber);

        if ($billable === null) {
            return [
                'response' => 'Your phone number is not linked to any account. Please contact support.',
                'is_terminal' => true,
            ];
        }

        if ($parts === []) {
            return $this->mainMenu();
        }

        return match ($parts[0]) {
            '1' => $this->handleMyAccount($billable, $parts),
            '2' => $this->handleMakePayment($sessionId, $billable, $parts),
            '3' => $this->handleCheckUsage($billable),
            '4' => $this->handleChangePlan($sessionId, $billable, $parts),
            default => [
                'response' => 'Invalid option. Please try again.',
                'is_terminal' => true,
            ],
        };
    }

    /**
     * Render the main menu.
     *
     * @return array{response: string, is_terminal: bool}
     */
    public function mainMenu(): array
    {
        $companyNameRaw = config('billing.invoices.company_name', 'Billing');
        $companyName = is_string($companyNameRaw) ? $companyNameRaw : 'Billing';

        return [
            'response' => "Welcome to {$companyName} Billing\n1. My Account\n2. Make Payment\n3. Check Usage\n4. Change Plan",
            'is_terminal' => false,
        ];
    }

    /**
     * Handle the "My Account" submenu.
     *
     * @param  array<int, string>  $parts
     * @return array{response: string, is_terminal: bool}
     */
    protected function handleMyAccount(Model&BillableInterface $billable, array $parts): array
    {
        $subscription = $billable->subscription();

        if ($subscription === null) {
            return [
                'response' => "You have no active subscription.\nVisit our website to subscribe.",
                'is_terminal' => true,
            ];
        }

        $plan = $subscription->plan;
        $status = $subscription->status->label();
        $nextBilling = $subscription->current_period_end?->format('Y-m-d') ?? 'N/A';
        $balance = self::formatMoney($this->getOutstandingBalance($billable));

        return [
            'response' => "Plan: {$plan->name}\nBalance: {$balance}\nNext billing: {$nextBilling}\nStatus: {$status}",
            'is_terminal' => true,
        ];
    }

    /**
     * Handle the "Make Payment" flow.
     *
     * @param  array<int, string>  $parts
     * @return array{response: string, is_terminal: bool}
     */
    protected function handleMakePayment(string $sessionId, Model&BillableInterface $billable, array $parts): array
    {
        // Step 1: Ask for amount
        if (count($parts) === 1) {
            return [
                'response' => 'Enter amount in KES:',
                'is_terminal' => false,
            ];
        }

        // Step 2: Confirm payment
        if (count($parts) === 2) {
            $amount = (int) $parts[1];

            if ($amount <= 0) {
                return [
                    'response' => 'Invalid amount. Please try again.',
                    'is_terminal' => true,
                ];
            }

            $formatted = self::formatMoney($amount * 100);

            $this->sessionManager->put($sessionId, [
                'action' => 'payment',
                'amount_cents' => $amount * 100,
            ]);

            return [
                'response' => "Pay {$formatted} via M-Pesa?\n1. Confirm\n2. Cancel",
                'is_terminal' => false,
            ];
        }

        // Step 3: Process confirmation
        if (count($parts) === 3) {
            return match ($parts[2]) {
                '1' => $this->processPayment($sessionId, $billable),
                '2' => [
                    'response' => 'Payment cancelled.',
                    'is_terminal' => true,
                ],
                default => [
                    'response' => 'Invalid option. Payment cancelled.',
                    'is_terminal' => true,
                ],
            };
        }

        return [
            'response' => 'Invalid input. Please try again.',
            'is_terminal' => true,
        ];
    }

    /**
     * Process a confirmed payment via M-Pesa STK push.
     *
     * @return array{response: string, is_terminal: bool}
     */
    protected function processPayment(string $sessionId, Model&BillableInterface $billable): array
    {
        $session = $this->sessionManager->get($sessionId);
        $amountCentsRaw = $session['amount_cents'] ?? 0;
        $amountCents = is_numeric($amountCentsRaw) ? (int) $amountCentsRaw : 0;

        if ($amountCents <= 0) {
            return [
                'response' => 'Session expired. Please try again.',
                'is_terminal' => true,
            ];
        }

        try {
            $provider = $this->paymentManager->driver('mpesa');
            if (! $provider instanceof PaymentProviderInterface) {
                throw new \RuntimeException('mpesa driver did not resolve to PaymentProviderInterface.');
            }
            $currencyRaw = billing_setting('currency', 'KES', $billable);
            $currency = is_string($currencyRaw) ? $currencyRaw : 'KES';

            $result = $provider->charge($amountCents, $currency, [
                'phone' => $this->getBillablePhone($billable),
                'description' => 'USSD Payment',
                'billable_id' => $billable->getKey(),
                'billable_type' => $billable->getMorphClass(),
            ]);

            $this->sessionManager->forget($sessionId);

            if ($result['success']) {
                $formatted = self::formatMoney($amountCents);

                return [
                    'response' => "Payment of {$formatted} initiated. You will receive an M-Pesa prompt shortly.",
                    'is_terminal' => true,
                ];
            }

            return [
                'response' => 'Payment could not be initiated. Please try again later.',
                'is_terminal' => true,
            ];
        } catch (\Throwable) {
            $this->sessionManager->forget($sessionId);

            return [
                'response' => 'Payment service unavailable. Please try again later.',
                'is_terminal' => true,
            ];
        }
    }

    /**
     * Handle the "Check Usage" menu option.
     *
     * @return array{response: string, is_terminal: bool}
     */
    protected function handleCheckUsage(Model&BillableInterface $billable): array
    {
        $subscription = $billable->subscription();

        if ($subscription === null) {
            return [
                'response' => 'No active subscription found.',
                'is_terminal' => true,
            ];
        }

        $plan = $subscription->plan;
        $limits = $plan->limits ?? [];

        if ($limits === []) {
            return [
                'response' => 'Your plan has no metered features.',
                'is_terminal' => true,
            ];
        }

        $lines = [];

        foreach ($limits as $feature => $limit) {
            $featureSlug = (string) $feature;
            $usage = $billable->usage($featureSlug);
            $limitInt = (int) $limit;
            $percentage = $limitInt > 0 ? (int) round(($usage / $limitInt) * 100) : 0;
            $label = str_replace('_', ' ', ucfirst($featureSlug));
            $lines[] = "{$label}: {$usage}/{$limitInt} ({$percentage}%)";
        }

        return [
            'response' => implode("\n", $lines),
            'is_terminal' => true,
        ];
    }

    /**
     * Handle the "Change Plan" flow.
     *
     * @param  array<int, string>  $parts
     * @return array{response: string, is_terminal: bool}
     */
    protected function handleChangePlan(string $sessionId, Model&BillableInterface $billable, array $parts): array
    {
        // Step 1: Show available plans
        if (count($parts) === 1) {
            return $this->showPlanList($billable);
        }

        // Step 2: Confirm plan change
        if (count($parts) === 2) {
            $planIndex = (int) $parts[1];
            $plans = Plan::active()->ordered()->get();

            if ($planIndex < 1 || $planIndex > $plans->count()) {
                return [
                    'response' => 'Invalid plan selection.',
                    'is_terminal' => true,
                ];
            }

            $selectedPlan = $plans->get($planIndex - 1);

            if ($selectedPlan === null) {
                return [
                    'response' => 'Invalid plan selection.',
                    'is_terminal' => true,
                ];
            }

            $this->sessionManager->put($sessionId, [
                'action' => 'change_plan',
                'plan_id' => $selectedPlan->id,
            ]);

            $price = self::formatMoney($selectedPlan->base_price);
            $cycle = $selectedPlan->billing_cycle->label();

            return [
                'response' => "Switch to {$selectedPlan->name} at {$price}/{$cycle}?\n1. Confirm\n2. Cancel",
                'is_terminal' => false,
            ];
        }

        // Step 3: Process confirmation
        if (count($parts) === 3) {
            return match ($parts[2]) {
                '1' => $this->processChangePlan($sessionId, $billable),
                '2' => [
                    'response' => 'Plan change cancelled.',
                    'is_terminal' => true,
                ],
                default => [
                    'response' => 'Invalid option. Plan change cancelled.',
                    'is_terminal' => true,
                ],
            };
        }

        return [
            'response' => 'Invalid input. Please try again.',
            'is_terminal' => true,
        ];
    }

    /**
     * Show the list of available plans.
     *
     * @return array{response: string, is_terminal: bool}
     */
    protected function showPlanList(Model&BillableInterface $billable): array
    {
        $plans = Plan::active()->ordered()->get();

        if ($plans->isEmpty()) {
            return [
                'response' => 'No plans available at this time.',
                'is_terminal' => true,
            ];
        }

        $currentSubscription = $billable->subscription();
        $currentPlanId = $currentSubscription?->plan_id;

        $lines = [];

        $index = 0;
        foreach ($plans as $plan) {
            $number = $index + 1;
            $price = self::formatMoney($plan->base_price);
            $cycle = strtolower($plan->billing_cycle->label());
            $shortCycle = match ($cycle) {
                'monthly' => 'mo',
                'quarterly' => 'qtr',
                'annual' => 'yr',
                default => $cycle,
            };
            $current = $plan->id === $currentPlanId ? ' (current)' : '';
            $lines[] = "{$number}. {$plan->name} - {$price}/{$shortCycle}{$current}";
            $index++;
        }

        return [
            'response' => implode("\n", $lines),
            'is_terminal' => false,
        ];
    }

    /**
     * Process a confirmed plan change.
     *
     * @return array{response: string, is_terminal: bool}
     */
    protected function processChangePlan(string $sessionId, Model&BillableInterface $billable): array
    {
        $session = $this->sessionManager->get($sessionId);
        $planId = $session['plan_id'] ?? null;

        if (! is_int($planId) && ! is_string($planId)) {
            return [
                'response' => 'Session expired. Please try again.',
                'is_terminal' => true,
            ];
        }

        $plan = Plan::find($planId);

        if ($plan === null) {
            return [
                'response' => 'Plan not found. Please try again.',
                'is_terminal' => true,
            ];
        }

        $currentSubscription = $billable->subscription();

        if ($currentSubscription !== null && $currentSubscription->plan_id === $plan->id) {
            $this->sessionManager->forget($sessionId);

            return [
                'response' => 'You are already on this plan.',
                'is_terminal' => true,
            ];
        }

        // Update the subscription plan
        if ($currentSubscription !== null) {
            $currentSubscription->update(['plan_id' => $plan->id]);
        }

        $this->sessionManager->forget($sessionId);

        return [
            'response' => "Plan changed to {$plan->name} successfully.",
            'is_terminal' => true,
        ];
    }

    /**
     * Resolve the billable entity by phone number.
     *
     * @return (Model&BillableInterface)|null
     */
    public function resolveBillable(string $phoneNumber): ?BillableInterface
    {
        $modelClassRaw = config('billing.billable_model', 'App\\Models\\Company');
        $modelClass = is_string($modelClassRaw) ? $modelClassRaw : 'App\\Models\\Company';
        $phoneFieldRaw = config('billing.ussd.phone_field', 'phone');
        $phoneField = is_string($phoneFieldRaw) ? $phoneFieldRaw : 'phone';

        if (! class_exists($modelClass)) {
            return null;
        }

        $model = new $modelClass;

        if (! $model instanceof Model) {
            return null;
        }

        $resolved = $model->newQuery()
            ->where($phoneField, $phoneNumber)
            ->first();

        if ($resolved instanceof Model && $resolved instanceof BillableInterface) {
            return $resolved;
        }

        return null;
    }

    /**
     * Get the phone number from a billable model.
     */
    protected function getBillablePhone(Model $billable): string
    {
        $phoneFieldRaw = config('billing.ussd.phone_field', 'phone');
        $phoneField = is_string($phoneFieldRaw) ? $phoneFieldRaw : 'phone';

        $value = $billable->getAttribute($phoneField);

        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /**
     * Get the outstanding balance for a billable (sum of pending payments in cents).
     */
    protected function getOutstandingBalance(Model&BillableInterface $billable): int
    {
        return (int) $billable->payments()
            ->where('status', 'pending')
            ->sum('amount');
    }

    /**
     * Format an amount in cents to a human-readable KES string.
     */
    public static function formatMoney(int $cents): string
    {
        $currencyRaw = billing_setting('currency', 'KES');
        $currency = is_string($currencyRaw) ? $currencyRaw : 'KES';

        return $currency.' '.number_format($cents / 100, 2);
    }
}
