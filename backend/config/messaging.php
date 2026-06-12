<?php
return [
    'idempotency_ttl_hours' => (int) env('MESSAGING_IDEMPOTENCY_TTL_HOURS', 24),
    'transactional_email_credits' => (int) env('MESSAGING_TRANSACTIONAL_EMAIL_CREDITS', 0),
    'refund_on_undeliverable' => (bool) env('MESSAGING_REFUND_ON_UNDELIVERABLE', false),
    // Allowlist de hosts para audio_url (voice messages). Casa por sufixo de domínio.
    // Default cobre os CDNs mais comuns. Adicione seu CDN próprio se necessário.
    'audio_url_allowlist' => array_filter(
        explode(',', env('MESSAGING_AUDIO_URL_ALLOWLIST',
            's3.amazonaws.com,storage.googleapis.com,firebasestorage.googleapis.com,'
            .'r2.cloudflarestorage.com,supabase.co,vercel-storage.com,'
            .'b-cdn.net,blob.core.windows.net,digitaloceanspaces.com,'
            .'wasabisys.com,backblazeb2.com,businesscode.com.br'))
    ),
    // Se true, qualquer URL HTTPS pública é aceita (mantém só bloqueio de IPs privados).
    // Use em dev/staging ou quando o operador confia plenamente nos tenants.
    'audio_url_allow_any' => filter_var(env('MESSAGING_AUDIO_URL_ALLOW_ANY', false), FILTER_VALIDATE_BOOLEAN),
    'quiet_hours' => [
        'default_timezone' => env('MESSAGING_QUIET_HOURS_DEFAULT_TZ', 'America/Sao_Paulo'),
        'default_start'    => env('MESSAGING_QUIET_HOURS_DEFAULT_START', '22:00'),
        'default_end'      => env('MESSAGING_QUIET_HOURS_DEFAULT_END', '08:00'),
    ],
    'rate_limits' => [
        'sms'   => (int) env('MESSAGING_RATE_LIMIT_SMS',   300),
        'voice' => (int) env('MESSAGING_RATE_LIMIT_VOICE', 10),
        'email' => (int) env('MESSAGING_RATE_LIMIT_EMAIL', 120),
    ],
    'inbound_webhook_secret' => env('INFOBIP_INBOUND_WEBHOOK_SECRET', ''),
    'opt_out_keywords_in'  => ['SAIR', 'STOP', 'CANCELAR', 'PARAR', 'REMOVER'],
    'opt_out_keywords_out' => ['ENTRAR', 'START'],
];
