<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Moffhub\Billing\Events\PlanChanged;
use Moffhub\Billing\Events\SubscriptionCancelled;
use Moffhub\Billing\Http\Requests\ChangeSubscriptionPlanRequest;
use Moffhub\Billing\Http\Requests\StoreSubscriptionRequest;
use Moffhub\Billing\Http\Resources\SubscriptionResource;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Services\FeatureResolver;
use Moffhub\Billing\Services\ProrationCalculator;

class SubscriptionController extends Controller
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
        $subscription = Subscription::with('plan', 'addons.feature')
            ->findOrFail($subscription);

        return response()->json([
            'data' => new SubscriptionResource($subscription),
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

        $builder = $billable->subscribe($request->input('plan'));

        if ($request->filled('trial_days')) {
            $builder->trialDays($request->integer('trial_days'));
        }

        if ($request->filled('payment_provider')) {
            $builder->provider($request->input('payment_provider'));
        }

        if ($request->filled('metadata')) {
            $builder->withMetadata($request->input('metadata'));
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
        $subscription = Subscription::with('plan')->findOrFail($subscription);

        $oldPlan = $subscription->plan;
        $newPlan = Plan::where('slug', $request->input('plan'))->firstOrFail();

        if ($oldPlan->id === $newPlan->id) {
            return response()->json([
                'message' => 'Already on this plan.',
            ], 422);
        }

        $subscription->update([
            'plan_id' => $newPlan->id,
        ]);

        // Clear feature cache
        $billable = $subscription->billable;
        app(FeatureResolver::class)->clearCache($billable);

        $proration = app(ProrationCalculator::class)->calculate($subscription, $newPlan);

        PlanChanged::dispatch(
            $subscription->fresh(),
            $billable,
            $oldPlan,
            $newPlan,
            $proration['net'],
        );

        return response()->json([
            'message' => "Plan changed from {$oldPlan->name} to {$newPlan->name}.",
            'data' => new SubscriptionResource($subscription->fresh()->load('plan')),
        ]);
    }

    /**
     * Cancel a subscription.
     */
    public function cancel(Request $request, int $subscription): JsonResponse
    {
        $subscription = Subscription::findOrFail($subscription);

        $immediately = $request->boolean('immediately', false);
        $subscription->cancel($immediately);

        $subscription->load('plan', 'billable');

        SubscriptionCancelled::dispatch(
            $subscription,
            $subscription->billable,
            $subscription->plan,
            $subscription->cancelled_at,
            $immediately ? null : $subscription->current_period_end,
            $immediately,
        );

        $message = $immediately
            ? 'Subscription cancelled immediately.'
            : 'Subscription will be cancelled at the end of the billing period.';

        return response()->json([
            'message' => $message,
            'data' => new SubscriptionResource($subscription->fresh()->load('plan')),
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

        $subscription = Subscription::findOrFail($subscription);
        $subscription->pause();

        return response()->json([
            'message' => 'Subscription paused.',
            'data' => new SubscriptionResource($subscription->fresh()->load('plan')),
        ]);
    }

    /**
     * Resume a paused subscription.
     */
    public function resume(int $subscription): JsonResponse
    {
        $subscription = Subscription::findOrFail($subscription);
        $subscription->resume();

        return response()->json([
            'message' => 'Subscription resumed.',
            'data' => new SubscriptionResource($subscription->fresh()->load('plan')),
        ]);
    }

    /**
     * Resolve the billable entity from the request.
     */
    protected function resolveBillable(Request $request): mixed
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (method_exists($user, 'subscriptions')) {
            return $user;
        }

        $billableRelation = config('billing.billable_relation', 'company');

        if (method_exists($user, $billableRelation)) {
            return $user->{$billableRelation};
        }

        return null;
    }
}
