<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCreditLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credit_limit_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'reason'             => ['required', 'string', 'min:5', 'max:200'],
        ];
    }
}
