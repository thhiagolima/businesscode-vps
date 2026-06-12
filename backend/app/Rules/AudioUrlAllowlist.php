<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AudioUrlAllowlist implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value) {
            return;
        }

        $parts = parse_url($value);
        if (! isset($parts['scheme']) || $parts['scheme'] !== 'https') {
            $fail('audio_url deve ser HTTPS');
            return;
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            $fail('audio_url inválida (host ausente)');
            return;
        }

        // Sempre rejeitar IPs privados/loopback (defesa contra abuso mesmo se allow_any=true)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPrivate = ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($isPrivate) {
                $fail("audio_url IP {$host} é privado/reservado");
                return;
            }
        }

        // Modo permissivo: qualquer HTTPS público é aceito.
        if (config('messaging.audio_url_allow_any', false)) {
            return;
        }

        $allow = config('messaging.audio_url_allowlist', []);
        foreach ($allow as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '') continue;
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return;
            }
        }

        $fail("audio_url host '{$host}' não está na allowlist. " .
              "Adicione ao MESSAGING_AUDIO_URL_ALLOWLIST no .env (separado por vírgula) " .
              "ou ative MESSAGING_AUDIO_URL_ALLOW_ANY=true.");
    }
}
