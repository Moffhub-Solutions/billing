<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Moffhub\Billing\Http\Resources\UsageRecordResource;
use Moffhub\Billing\Services\UsageService;

class UsageController extends BillingController
{
    public function __construct(
        protected UsageService $usageService,
    ) {}

    /**
     * Get usage summary for all metered features.
     */
    public function index(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $records = $billable->usageRecords()
            ->currentPeriod()
            ->get();

        return response()->json([
            'data' => UsageRecordResource::collection($records),
        ]);
    }

    /**
     * Get usage details for a specific feature.
     */
    public function show(Request $request, string $featureSlug): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $record = $billable->usageRecords()
            ->where('feature_slug', $featureSlug)
            ->currentPeriod()
            ->first();

        if ($record === null) {
            return response()->json([
                'data' => [
                    'feature_slug' => $featureSlug,
                    'usage_count' => 0,
                    'usage_limit' => $billable->usageLimit($featureSlug),
                    'remaining' => $billable->remainingQuota($featureSlug),
                    'percentage' => 0,
                ],
            ]);
        }

        return response()->json([
            'data' => new UsageRecordResource($record),
        ]);
    }

    /**
     * Record usage for a metered feature.
     */
    public function record(Request $request, string $featureSlug): JsonResponse
    {
        $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'transaction_id' => ['sometimes', 'string', 'max:255'],
            'properties' => ['sometimes', 'array'],
        ]);

        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        // Check feature access first
        if (! $billable->hasFeature($featureSlug)) {
            return response()->json([
                'message' => "Feature '{$featureSlug}' is not available on your current plan.",
            ], 403);
        }

        // Check usage limit
        $remaining = $billable->remainingQuota($featureSlug);

        if ($remaining !== null && $remaining <= 0 && ! billing_setting('usage.allow_overage', false, $billable)) {
            return response()->json([
                'message' => "Usage limit reached for '{$featureSlug}'.",
                'usage' => [
                    'used' => $billable->usage($featureSlug),
                    'limit' => $billable->usageLimit($featureSlug),
                ],
            ], 429);
        }

        $transactionIdRaw = $request->input('transaction_id');
        $transactionId = is_string($transactionIdRaw) ? $transactionIdRaw : null;

        $propertiesRaw = $request->input('properties', []);
        $properties = is_array($propertiesRaw) ? $propertiesRaw : [];

        $event = $this->usageService->record(
            $billable,
            $featureSlug,
            $request->integer('quantity', 1),
            $transactionId,
            $properties,
        );

        return response()->json([
            'message' => 'Usage recorded.',
            'data' => [
                'event_id' => $event->ulid,
                'feature_slug' => $featureSlug,
                'quantity' => $event->quantity,
                'current_usage' => $billable->usage($featureSlug),
                'limit' => $billable->usageLimit($featureSlug),
                'remaining' => $billable->remainingQuota($featureSlug),
            ],
        ], 201);
    }
}
