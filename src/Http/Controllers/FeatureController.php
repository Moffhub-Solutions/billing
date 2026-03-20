<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Moffhub\Billing\Http\Requests\StoreFeatureRequest;
use Moffhub\Billing\Http\Requests\UpdateFeatureRequest;
use Moffhub\Billing\Http\Resources\FeatureResource;
use Moffhub\Billing\Models\Feature;

class FeatureController extends Controller
{
    /**
     * List all features.
     */
    public function index(Request $request): JsonResponse
    {
        $features = Feature::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => FeatureResource::collection($features),
        ]);
    }

    /**
     * List only add-on features.
     */
    public function addons(Request $request): JsonResponse
    {
        $addons = Feature::active()
            ->addons()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => FeatureResource::collection($addons),
        ]);
    }

    /**
     * Show a single feature by slug.
     */
    public function show(string $feature): JsonResponse
    {
        $feature = Feature::where('slug', $feature)
            ->orWhere('id', $feature)
            ->firstOrFail();

        return response()->json([
            'data' => new FeatureResource($feature),
        ]);
    }

    /**
     * Create a new feature.
     */
    public function store(StoreFeatureRequest $request): JsonResponse
    {
        $feature = Feature::create($request->validated());

        return response()->json([
            'message' => 'Feature created successfully.',
            'data' => new FeatureResource($feature),
        ], 201);
    }

    /**
     * Update a feature.
     */
    public function update(UpdateFeatureRequest $request, string $feature): JsonResponse
    {
        $feature = Feature::where('slug', $feature)
            ->orWhere('id', $feature)
            ->firstOrFail();

        $feature->update($request->validated());

        return response()->json([
            'message' => 'Feature updated successfully.',
            'data' => new FeatureResource($feature->fresh()),
        ]);
    }

    /**
     * Deactivate a feature.
     */
    public function destroy(string $feature): JsonResponse
    {
        $feature = Feature::where('slug', $feature)
            ->orWhere('id', $feature)
            ->firstOrFail();

        $feature->update(['is_active' => false]);

        return response()->json([
            'message' => 'Feature deactivated successfully.',
        ]);
    }
}
