<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Moffhub\Billing\Http\Resources\PaymentTokenResource;

class PaymentTokenController extends BillingController
{
    /**
     * List saved payment methods for the billable.
     */
    public function index(Request $request): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $tokens = $billable->paymentTokens()
            ->usable()
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->get();

        return response()->json([
            'data' => PaymentTokenResource::collection($tokens),
        ]);
    }

    /**
     * Save a new payment method / token.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:mpesa,paystack,flutterwave,pesapal'],
            'token_type' => ['required', 'string', 'in:card,mpesa,bank_account,mobile_money'],
            'token' => ['required', 'string', 'max:500'],
            'last_four' => ['nullable', 'string', 'size:4'],
            'card_brand' => ['nullable', 'string', 'max:50'],
            'card_exp_month' => ['nullable', 'string', 'size:2'],
            'card_exp_year' => ['nullable', 'string', 'size:4'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        // If setting as default, unset other defaults
        if ($request->boolean('is_default')) {
            $billable->paymentTokens()->update(['is_default' => false]);
        }

        // Make first token the default automatically
        $isFirst = ! $billable->paymentTokens()->exists();

        $token = $billable->paymentTokens()->create([
            'ulid' => Str::ulid()->toBase32(),
            'is_reusable' => true,
            'is_default' => $request->boolean('is_default') || $isFirst,
            ...$validated,
        ]);

        return response()->json([
            'message' => 'Payment method saved.',
            'data' => new PaymentTokenResource($token),
        ], 201);
    }

    /**
     * Set a payment method as default.
     */
    public function setDefault(Request $request, int $token): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $token = $billable->paymentTokens()->findOrFail($token);

        // Unset all defaults, then set this one
        $billable->paymentTokens()->update(['is_default' => false]);
        $token->update(['is_default' => true]);

        return response()->json([
            'message' => 'Default payment method updated.',
            'data' => new PaymentTokenResource($token->fresh()),
        ]);
    }

    /**
     * Remove a saved payment method.
     */
    public function destroy(Request $request, int $token): JsonResponse
    {
        $billable = $this->resolveBillable($request);

        if ($billable === null) {
            return response()->json(['message' => 'No billable entity found.'], 404);
        }

        $token = $billable->paymentTokens()->findOrFail($token);
        $wasDefault = $token->is_default;

        $token->delete();

        // If we deleted the default, promote the most recently used
        if ($wasDefault) {
            $newDefault = $billable->paymentTokens()->usable()->orderByDesc('last_used_at')->first();
            $newDefault?->update(['is_default' => true]);
        }

        return response()->json(['message' => 'Payment method removed.']);
    }
}
