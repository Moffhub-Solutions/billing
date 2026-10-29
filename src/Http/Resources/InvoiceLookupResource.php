<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Moffhub\Billing\Models\Invoice;

/**
 * An invoice as a back-office person picks it when allocating a payment: the
 * numeric id the allocate call takes, what is still owed and who owes it.
 *
 * @mixin Invoice
 */
class InvoiceLookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        $name = data_get($this->billable, 'name');

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'total' => $this->total,
            'outstanding_balance' => $this->outstandingBalance(),
            'currency' => $this->currency,
            'due_date' => $this->due_date?->toDateString(),
            'billable_name' => is_string($name) ? $name : null,
        ];
    }
}
