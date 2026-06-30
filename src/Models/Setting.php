<?php

declare(strict_types=1);

namespace Moffhub\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Moffhub\Billing\Services\BillingSettings;

/**
 * A database-backed override for a runtime-overridable billing config key.
 *
 * Global rows store empty-string scope columns; per-billable rows store the
 * billable's morph type and key. Resolution and caching live in the
 * {@see BillingSettings} resolver, not here.
 *
 * @property int $id
 * @property string $key
 * @property string $billable_type
 * @property string $billable_id
 * @property mixed $value
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Setting extends Model
{
    protected $guarded = ['id'];

    #[\Override]
    protected function casts(): array
    {
        return [
            // `json` round-trips scalars, booleans, and arrays with their type intact.
            'value' => 'json',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        $value = config('billing.tables.settings', 'billing_settings');

        return is_string($value) ? $value : 'billing_settings';
    }
}
