<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeSubscriptionPlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'max:255'],
        ];
    }
}
