<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Moffhub\Billing\Events\PlanChanged;
use Moffhub\Billing\Events\SubscriptionCancelled;
use Moffhub\Billing\Http\Requests\ChangeSubscriptionPlanRequest;
use Moffhub\Billing\Http\Requests\StoreSubscriptionRequest;
use Moffhub\Billing\Http\Resources\SubscriptionResource;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Services\FeatureResolver;
use Moffhub\Billing\Services\ProrationCalculator;

class SubscriptionController extends BillingController
{
    /**
     * List subscriptions for the authenticated billable.
     */
    public function index(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $subscriptions = $billable->subscriptions()
            ->with('plan')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => SubscriptionResource::collection($subscriptions->items()),
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
            ],
        ]);
    }

    /**
     * Get the current active subscription.
     */
    public function current(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $subscription = $billable->subscription();

        if ($subscription === null) {
            return response()->json(['message' => 'No active subscription.'], 404);
        }

        $subscription->load('plan', 'addons.feature');

        // Include available features and usage summary
        $featureResolver = app(FeatureResolver::class);
        $availableFeatures = $featureResolver->getAvailableFeatures($billable);

        $usageSummary = [];
        foreach ($subscription->plan->limits ?? [] as $key => $limit) {
            $usageSummary[$key] = [
                'used' => $billable->usage($key),
                'limit' => $limit,
                'remaining' => $billable->remainingQuota($key),
                'percentage' => $billable->usagePercentage($key),
            ];
        }

        return response()->json([
            'data' => new SubscriptionResource($subscription),
            'features' => $availableFeatures,
            'usage' => $usageSummary,
        ]);
    }

    /**
     * Show a specific subscription.
     */
    public function show(int $subscription): JsonResponse
    {
        $subscriptionModel = Subscription::query()->with('plan', 'addons.feature')
            ->findOrFail($subscription);

        return response()->json([
            'data' => new SubscriptionResource($subscriptionModel),
        ]);
    }

    /**
     * Create a new subscription.
     */
    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        // Check if already subscribed
        if ($billable->subscribed()) {
            return response()->json([
                'message' => 'Already subscribed. Use change-plan to switch plans.',
            ], 422);
        }

        $builder = $billable->subscribe($request->string('plan')->toString());

        if ($request->filled('trial_days')) {
            $builder->trialDays($request->integer('trial_days'));
        }

        if ($request->filled('payment_provider')) {
            $builder->provider($request->string('payment_provider')->toString());
        }

        if ($request->filled('metadata')) {
            $metadataRaw = $request->input('metadata');
            if (is_array($metadataRaw)) {
                $metadata = [];
                foreach ($metadataRaw as $k => $v) {
                    if (is_string($k)) {
                        $metadata[$k] = $v;
                    }
                }
                $builder->withMetadata($metadata);
            }
        }

        $subscription = $builder->create();
        $subscription->load('plan');

        return response()->json([
            'message' => 'Subscription created successfully.',
            'data' => new SubscriptionResource($subscription),
        ], 201);
    }

    /**
     * Change the plan on an existing subscription.
     */
    public function changePlan(ChangeSubscriptionPlanRequest $request, int $subscription): JsonResponse
    {
        $subscriptionModel = Subscription::query()->with('plan')->findOrFail($subscription);

        $oldPlan = $subscriptionModel->plan;
        $newPlan = Plan::query()->where('slug', $request->input('plan'))->firstOrFail();

        if ($oldPlan->id === $newPlan->id) {
            return response()->json([
                'message' => 'Already on this plan.',
            ], 422);
        }

        $subscriptionModel->update([
            'plan_id' => $newPlan->id,
        ]);

        // Clear feature cache
        $billable = $subscriptionModel->billable;
        app(FeatureResolver::class)->clearCache($billable);

        $proration = app(ProrationCalculator::class)->calculate($subscriptionModel, $newPlan);

        $fresh = $subscriptionModel->fresh();

        if ($fresh !== null) {
            PlanChanged::dispatch(
                $fresh,
                $billable,
                $oldPlan,
                $newPlan,
                $proration['net'],
            );
        }

        $reloaded = $subscriptionModel->fresh();
        $loaded = $reloaded !== null ? $reloaded->load('plan') : $subscriptionModel;

        return response()->json([
            'message' => "Plan changed from {$oldPlan->name} to {$newPlan->name}.",
            'data' => new SubscriptionResource($loaded),
        ]);
    }

    /**
     * Cancel a subscription.
     */
    public function cancel(Request $request, int $subscription): JsonResponse
    {
        $subscriptionModel = Subscription::query()->findOrFail($subscription);

        $immediately = $request->boolean('immediately', false);
        $subscriptionModel->cancel($immediately);

        $subscriptionModel->load('plan', 'billable');

        $cancelledAt = $subscriptionModel->cancelled_at;

        if ($cancelledAt !== null) {
            SubscriptionCancelled::dispatch(
                $subscriptionModel,
                $subscriptionModel->billable,
                $subscriptionModel->plan,
                $cancelledAt,
                $immediately ? null : $subscriptionModel->current_period_end,
                $immediately,
            );
        }

        $message = $immediately
            ? 'Subscription cancelled immediately.'
            : 'Subscription will be cancelled at the end of the billing period.';

        $reloaded = $subscriptionModel->fresh();
        $loaded = $reloaded !== null ? $reloaded->load('plan') : $subscriptionModel;

        return response()->json([
            'message' => $message,
            'data' => new SubscriptionResource($loaded),
        ]);
    }

    /**
     * Pause a subscription.
     */
    public function pause(int $subscription): JsonResponse
    {
        if (! config('billing.subscriptions.allow_pause', true)) {
            return response()->json(['message' => 'Pausing subscriptions is not enabled.'], 422);
        }

        $subscriptionModel = Subscription::query()->findOrFail($subscription);
        $subscriptionModel->pause();

        $reloaded = $subscriptionModel->fresh();
        $loaded = $reloaded !== null ? $reloaded->load('plan') : $subscriptionModel;

        return response()->json([
            'message' => 'Subscription paused.',
            'data' => new SubscriptionResource($loaded),
        ]);
    }

    /**
     * Resume a paused subscription.
     */
    public function resume(int $subscription): JsonResponse
    {
        $subscriptionModel = Subscription::query()->findOrFail($subscription);
        $subscriptionModel->resume();

        $reloaded = $subscriptionModel->fresh();
        $loaded = $reloaded !== null ? $reloaded->load('plan') : $subscriptionModel;

        return response()->json([
            'message' => 'Subscription resumed.',
            'data' => new SubscriptionResource($loaded),
        ]);
    }
}
