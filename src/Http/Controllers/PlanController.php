<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Moffhub\Billing\Http\Requests\StorePlanRequest;
use Moffhub\Billing\Http\Requests\UpdatePlanRequest;
use Moffhub\Billing\Http\Resources\PlanResource;
use Moffhub\Billing\Models\Plan;

class PlanController extends Controller
{
    /**
     * List all active plans.
     */
    public function index(Request $request): JsonResponse
    {
        $plans = Plan::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->ordered()
            ->get();

        return response()->json([
            'data' => PlanResource::collection($plans),
        ]);
    }

    /**
     * Show a single plan by slug or ID.
     */
    public function show(string $plan): JsonResponse
    {
        $plan = Plan::where('slug', $plan)
            ->orWhere('id', $plan)
            ->orWhere('ulid', $plan)
            ->firstOrFail();

        return response()->json([
            'data' => new PlanResource($plan),
        ]);
    }

    /**
     * Create a new plan.
     */
    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            ...$request->validated(),
        ]);

        return response()->json([
            'message' => 'Plan created successfully.',
            'data' => new PlanResource($plan),
        ], 201);
    }

    /**
     * Update an existing plan.
     */
    public function update(UpdatePlanRequest $request, string $plan): JsonResponse
    {
        $plan = Plan::where('slug', $plan)
            ->orWhere('id', $plan)
            ->orWhere('ulid', $plan)
            ->firstOrFail();

        $plan->update($request->validated());

        return response()->json([
            'message' => 'Plan updated successfully.',
            'data' => new PlanResource($plan->fresh()),
        ]);
    }

    /**
     * Delete a plan (soft — sets is_active to false).
     */
    public function destroy(string $plan): JsonResponse
    {
        $plan = Plan::where('slug', $plan)
            ->orWhere('id', $plan)
            ->orWhere('ulid', $plan)
            ->firstOrFail();

        // Don't hard-delete if there are active subscriptions
        $activeSubscriptions = $plan->subscriptions()->active()->count();

        if ($activeSubscriptions > 0) {
            return response()->json([
                'message' => "Cannot delete plan with {$activeSubscriptions} active subscription(s). Deactivate it instead.",
            ], 422);
        }

        $plan->update(['is_active' => false]);

        return response()->json([
            'message' => 'Plan deactivated successfully.',
        ]);
    }
}
