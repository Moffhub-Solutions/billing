<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_id
 * @property string $provider
 * @property string $provider_payment_id
 * @property string $billing_status
 * @property string|null $provider_status
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Payment $payment
 */
class ReconciliationDrift extends Model
{
    protected $table = 'billing_reconciliation_drifts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
