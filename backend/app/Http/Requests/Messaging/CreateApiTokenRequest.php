<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class CreateApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:100'],
            'abilities'   => ['required', 'array', 'min:1'],
            'abilities.*' => ['in:messaging:sms,messaging:voice,messaging:email,messaging:read,messaging:*'],
            'expires_at'  => ['nullable', 'date', 'after:now'],
        ];
    }
}
