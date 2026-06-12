<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class E164Phone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!preg_match('/^\+[1-9]\d{6,14}$/', $value)) {
            $fail('O campo :attribute deve estar no formato E.164 (ex: +5511999999999).');
        }
    }
}
