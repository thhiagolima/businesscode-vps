<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class SendSmsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to'            => ['required', 'string', 'max:20'],
            'content'       => ['required', 'string', 'max:1600'],
            'from'          => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9]+$/'],
            'scheduled_for' => ['nullable', 'date', 'after:now', 'before:+30 days'],
            'meta'          => ['nullable', 'array'],
            // Optional template variables: {key} placeholders in `content` are
            // replaced by the corresponding value before dispatch.
            'variables'     => ['nullable', 'array', 'max:50'],
            'variables.*'   => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $vars = $this->input('variables');
            if (!is_array($vars)) return;
            foreach (array_keys($vars) as $key) {
                if (!is_string($key) || !preg_match('/^[A-Za-z0-9_]{1,50}$/', $key)) {
                    $v->errors()->add(
                        "variables.{$key}",
                        "Variable key '{$key}' must be alphanumeric/underscore, 1-50 chars."
                    );
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // Coerce scalar variable values (numbers, bools) to strings so the
        // `string` rule accepts them. Clients commonly send numeric values
        // like {"dias": 7}.
        $vars = $this->input('variables');
        if (is_array($vars)) {
            $coerced = [];
            foreach ($vars as $k => $val) {
                if (is_bool($val))      $coerced[$k] = $val ? 'true' : 'false';
                elseif (is_scalar($val)) $coerced[$k] = (string) $val;
                else                     $coerced[$k] = $val;
            }
            $this->merge(['variables' => $coerced]);
        }
    }
}
