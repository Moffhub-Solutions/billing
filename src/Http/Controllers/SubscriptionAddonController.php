<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Moffhub\Billing\Http\Resources\SubscriptionAddonResource;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Services\FeatureResolver;

class SubscriptionAddonController extends BillingController
{
    /**
     * List add-ons for a subscription.
     */
    public function index(Request $request, int $subscription): JsonResponse
    {
        $subscriptionModel = $this->resolveOwnedSubscription($request, $subscription);

        if ($subscriptionModel === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $addons = $subscriptionModel->addons()
            ->with('feature')
            ->get();

        return response()->json([
            'data' => SubscriptionAddonResource::collection($addons),
        ]);
    }

    /**
     * Add an add-on to a subscription.
     */
    public function store(Request $request, int $subscription): JsonResponse
    {
        $request->validate([
            'feature' => ['required', 'string'],
        ]);

        $subscriptionModel = $this->resolveOwnedSubscription($request, $subscription);

        if ($subscriptionModel === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $featureSlug = $request->string('feature')->toString();

        $feature = Feature::query()->where('slug', $featureSlug)
            ->where('is_addon', true)
            ->where('is_active', true)
            ->first();

        if ($feature === null) {
            return response()->json([
                'message' => "Feature '{$featureSlug}' is not available as an add-on.",
            ], 422);
        }

        // Check if already added
        $existing = $subscriptionModel->addons()
            ->where('feature_id', $feature->id)
            ->where('status', 'active')
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => "Add-on '{$feature->name}' is already active on this subscription.",
            ], 422);
        }

        // Price is intentionally NOT taken from the request: a tenant must not
        // be able to set their own add-on price (e.g. 0). It defaults to the
        // feature's addon_price; a custom price_override is a back-office action.
        $addon = $subscriptionModel->addons()->create([
            'feature_id' => $feature->id,
            'status' => 'active',
            'enabled_at' => now(),
        ]);

        $addon->load('feature');

        // Clear feature cache
        app(FeatureResolver::class)->clearCache($subscriptionModel->billable);

        return response()->json([
            'message' => "Add-on '{$feature->name}' enabled.",
            'data' => new SubscriptionAddonResource($addon),
        ], 201);
    }

    /**
     * Remove an add-on from a subscription.
     */
    public function destroy(Request $request, int $subscription, int $addon): JsonResponse
    {
        $subscriptionModel = $this->resolveOwnedSubscription($request, $subscription);

        if ($subscriptionModel === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $addonModel = $subscriptionModel->addons()->findOrFail($addon);

        $addonModel->update([
            'status' => 'cancelled',
            'disabled_at' => now(),
        ]);

        // Clear feature cache
        app(FeatureResolver::class)->clearCache($subscriptionModel->billable);

        return response()->json([
            'message' => 'Add-on removed.',
        ]);
    }

    /**
     * Resolve a subscription only if it belongs to the request's billable.
     * Returns null when there is no billable; throws 404 when the subscription
     * is not owned, so cross-tenant ids cannot be read or mutated.
     */
    private function resolveOwnedSubscription(Request $request, int $subscription): ?Subscription
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return null;
        }

        return Subscription::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->findOrFail($subscription);
    }
}
