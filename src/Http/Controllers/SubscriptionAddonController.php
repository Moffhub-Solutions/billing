<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Moffhub\Billing\Http\Resources\SubscriptionAddonResource;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Models\Subscription;
use Moffhub\Billing\Services\FeatureResolver;

class SubscriptionAddonController extends Controller
{
    /**
     * List add-ons for a subscription.
     */
    public function index(int $subscription): JsonResponse
    {
        $subscription = Subscription::findOrFail($subscription);

        $addons = $subscription->addons()
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

        $featureSlug = $request->string('feature')->toString();

        $subscriptionModel = Subscription::query()->findOrFail($subscription);

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

        $addon = $subscriptionModel->addons()->create([
            'feature_id' => $feature->id,
            'status' => 'active',
            'price_override' => $request->input('price_override'),
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
    public function destroy(int $subscription, int $addon): JsonResponse
    {
        $subscriptionModel = Subscription::query()->findOrFail($subscription);

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
}
