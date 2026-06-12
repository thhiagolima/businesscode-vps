<?php

namespace App\Http\Requests\Messaging;

use App\Services\Messaging\EmailDomainService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SendEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to'        => ['required', 'email:rfc'],
            'subject'   => ['required', 'string', 'max:255'],
            'content'   => ['required', 'string', 'max:65000'],
            'from'      => ['nullable', 'email:rfc'],
            // Display name shown in recipient's inbox (e.g. "Empresa Parceria").
            // Limited to 100 chars to fit common SMTP header conventions.
            'from_name' => ['nullable', 'string', 'max:100'],
            // Reply-To header: where replies go. If null, replies go to `from`.
            'reply_to'  => ['nullable', 'email:rfc'],
            'meta'      => ['nullable', 'array'],
            // Optional template variables: {key} placeholders in `subject` and
            // `content` are replaced before sanitization/dispatch. Variable
            // values are CRLF-stripped (subject) and HTML-sanitized (content)
            // by the controller pipeline.
            'variables'   => ['nullable', 'array', 'max:50'],
            'variables.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // CRLF stripping on every header-bound field — CWE-93 (SMTP header injection)
        $this->merge([
            'subject'   => str_replace(["\r", "\n"], '', (string) $this->input('subject', '')),
            'from'      => str_replace(["\r", "\n"], '', (string) $this->input('from', '')) ?: null,
            'from_name' => str_replace(["\r", "\n"], '', (string) $this->input('from_name', '')) ?: null,
            'reply_to'  => str_replace(["\r", "\n"], '', (string) $this->input('reply_to', '')) ?: null,
        ]);

        // Coerce scalar variable values to strings so the `string` rule accepts them.
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Variable key shape: alphanumeric + underscore, 1-50 chars
            $vars = $this->input('variables');
            if (is_array($vars)) {
                foreach (array_keys($vars) as $key) {
                    if (!is_string($key) || !preg_match('/^[A-Za-z0-9_]{1,50}$/', $key)) {
                        $v->errors()->add(
                            "variables.{$key}",
                            "Variable key '{$key}' must be alphanumeric/underscore, 1-50 chars."
                        );
                    }
                }
            }

            $from = $this->input('from');
            if (empty($from)) {
                return; // allow: falls back to system default
            }
            // Only enforce if basic email format is valid (let `email:rfc` raise its own error otherwise)
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $user = $this->user();
            if (!$user || !$user->tenant_id) {
                return; // unauthenticated requests will be blocked upstream
            }

            $service = app(EmailDomainService::class);
            $match = $service->findActiveForEmail((int) $user->tenant_id, (string) $from);
            if (!$match) {
                $v->errors()->add(
                    'from',
                    "O dominio do email 'from' nao esta autenticado para este tenant. " .
                    "Cadastre o dominio em /settings/email-domains e aguarde a verificacao."
                );
            }
        });
    }
}
