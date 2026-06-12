<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ManualBalanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Allows negative values for debits; 0 explicitly forbidden.
            'amount_cents' => ['required', 'integer', 'not_in:0'],
            'reason'       => ['required', 'string', 'min:10', 'max:200'],
        ];
    }
}
