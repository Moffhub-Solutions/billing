<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Casts\EncryptedString;
use Moffhub\Billing\Database\Factories\StandingOrderFactory;
use Moffhub\Billing\Enums\StandingOrderFrequency;
use Moffhub\Billing\Enums\StandingOrderStatus;

/**
 * @property int $id
 * @property string $ulid
 * @property string $billable_type
 * @property int $billable_id
 * @property int|null $subscription_id
 * @property string $provider
 * @property string|null $provider_reference
 * @property string|null $account_reference
 * @property string $name
 * @property int $amount
 * @property string $currency
 * @property StandingOrderFrequency $frequency
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property StandingOrderStatus $status
 * @property string|null $customer_phone
 * @property string|null $customer_phone_hash
 * @property string|null $customer_phone_last4
 * @property Carbon|null $consent_at
 * @property string|null $consent_ip
 * @property string|null $consent_user_agent
 * @property Carbon|null $last_executed_at
 * @property Carbon|null $next_expected_at
 * @property int $failure_count
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model $billable
 * @property-read Subscription|null $subscription
 *
 * @method static Builder<self> open()
 */
class StandingOrder extends Model
{
    /** @use HasFactory<StandingOrderFactory> */
    use HasFactory;

    protected static function newFactory(): StandingOrderFactory
    {
        return StandingOrderFactory::new();
    }

    protected $guarded = ['id'];

    /**
     * The raw MSISDN never appears in serialized output.
     */
    protected $hidden = ['customer_phone', 'customer_phone_hash'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'frequency' => StandingOrderFrequency::class,
            'status' => StandingOrderStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'customer_phone' => EncryptedString::class,
            'consent_at' => 'datetime',
            'last_executed_at' => 'datetime',
            'next_expected_at' => 'datetime',
            'failure_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.standing_orders', 'billing_standing_orders');

        return is_string($value) ? $value : 'billing_standing_orders';
    }

    /**
     * Store the MSISDN (encrypted by the cast), plus a lookup hash and last four digits.
     */
    public function setCustomerPhone(string $msisdn): static
    {
        $key = config('app.key');

        $this->customer_phone = $msisdn;
        $this->customer_phone_hash = hash_hmac('sha256', $msisdn, is_string($key) ? $key : '');
        $this->customer_phone_last4 = substr($msisdn, -4);

        return $this;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<StandingOrderExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(StandingOrderExecution::class);
    }

    public function isActive(): bool
    {
        return $this->status === StandingOrderStatus::Active;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            StandingOrderStatus::Pending->value,
            StandingOrderStatus::Active->value,
            StandingOrderStatus::Paused->value,
        ]);
    }
}
