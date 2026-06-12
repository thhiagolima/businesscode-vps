# Skill: Settings Global

## Quando Usar
Sempre que um service precisar ler ou salvar configurações
de integração (Infobip, Grok, ElevenLabs) ou qualquer
setting que pertença ao sistema (não a um tenant específico).

## Regra Fundamental
Settings globais têm `tenant_id = NULL`.
O GlobalScope do model `Setting` filtra por tenant automaticamente.
Para acessar settings globais, SEMPRE usar `withoutGlobalScopes()`.

---

## Implementação do SettingsService

`app/Services/SettingsService.php`:

```php
<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    // ─── SETTINGS GLOBAIS (tenant_id = NULL) ───────────────────────

    public function getGlobal(
        string $group,
        string $key,
        mixed  $default = null
    ): mixed {
        $cacheKey = "settings:global:{$group}:{$key}";

        return Cache::remember($cacheKey, 300, function () use ($group, $key, $default) {
            $setting = Setting::withoutGlobalScopes()
                ->whereNull('tenant_id')
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            if (!$setting) return $default;

            return $setting->type === 'encrypted'
                ? decrypt($setting->value)
                : $setting->value;
        });
    }

    public function upsertGlobal(
        string $group,
        string $key,
        mixed  $value,
        string $type = 'string'
    ): void {
        $stored = $type === 'encrypted' ? encrypt($value) : (string) $value;

        $exists = Setting::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('group', $group)
            ->where('key', $key)
            ->exists();

        if ($exists) {
            Setting::withoutGlobalScopes()
                ->whereNull('tenant_id')
                ->where('group', $group)
                ->where('key', $key)
                ->update([
                    'value'      => $stored,
                    'type'       => $type,
                    'updated_at' => now(),
                ]);
        } else {
            Setting::withoutGlobalScopes()->insert([
                'tenant_id'  => null,
                'group'      => $group,
                'key'        => $key,
                'value'      => $stored,
                'type'       => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget("settings:global:{$group}:{$key}");
    }

    // ─── SETTINGS DE TENANT ────────────────────────────────────────

    public function get(
        int    $tenantId,
        string $group,
        string $key,
        mixed  $default = null
    ): mixed {
        $cacheKey = "settings:{$tenantId}:{$group}:{$key}";

        return Cache::remember($cacheKey, 300, function () use ($tenantId, $group, $key, $default) {
            $setting = Setting::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            if (!$setting) return $default;

            return $setting->type === 'encrypted'
                ? decrypt($setting->value)
                : $setting->value;
        });
    }

    public function upsert(
        int    $tenantId,
        string $group,
        string $key,
        mixed  $value,
        string $type = 'string'
    ): void {
        $stored = $type === 'encrypted' ? encrypt($value) : (string) $value;

        $exists = Setting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('group', $group)
            ->where('key', $key)
            ->exists();

        if ($exists) {
            Setting::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('group', $group)
                ->where('key', $key)
                ->update([
                    'value'      => $stored,
                    'type'       => $type,
                    'updated_at' => now(),
                ]);
        } else {
            Setting::withoutGlobalScopes()->insert([
                'tenant_id'  => $tenantId,
                'group'      => $group,
                'key'        => $key,
                'value'      => $stored,
                'type'       => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget("settings:{$tenantId}:{$group}:{$key}");
    }

    public function forget(string $group, string $key, ?int $tenantId = null): void
    {
        $prefix = $tenantId === null ? 'global' : (string) $tenantId;
        Cache::forget("settings:{$prefix}:{$group}:{$key}");
    }
}
```

---

## Padrões de Uso por Integração

### Infobip
```php
// Ler
$apiKey   = $this->settings->getGlobal('infobip', 'api_key');
$baseUrl  = $this->settings->getGlobal('infobip', 'base_url');
$senderSms = $this->settings->getGlobal('infobip', 'sender_sms', 'CampaignAI');

// Salvar (admin)
$this->settings->upsertGlobal('infobip', 'api_key',  $apiKey,  'encrypted');
$this->settings->upsertGlobal('infobip', 'base_url', $baseUrl, 'string');
```

### Grok (xAI)
```php
// Ler
$apiKey = $this->settings->getGlobal('ai', 'grok_api_key');
$model  = $this->settings->getGlobal('ai', 'grok_model', 'grok-beta');

// Salvar
$this->settings->upsertGlobal('ai', 'grok_api_key', $apiKey, 'encrypted');
$this->settings->upsertGlobal('ai', 'grok_model',   $model,  'string');
```

### ElevenLabs
```php
// Ler
$apiKey  = $this->settings->getGlobal('elevenlabs', 'api_key');
$modelId = $this->settings->getGlobal('elevenlabs', 'model_id', 'eleven_multilingual_v2');
$costPerChar = $this->settings->getGlobal('elevenlabs', 'cost_per_char', '0.0003');
$salePerChar = $this->settings->getGlobal('elevenlabs', 'sale_per_char', '0.001');

// Salvar
$this->settings->upsertGlobal('elevenlabs', 'api_key', $apiKey, 'encrypted');
```

### Billing (custo de créditos)
```php
// Ler
$creditsSms   = (int) $this->settings->getGlobal('billing', 'credits_per_sms',   '1');
$creditsVoice = (int) $this->settings->getGlobal('billing', 'credits_per_voice', '5');
$creditsEmail = (int) $this->settings->getGlobal('billing', 'credits_per_email', '2');
$creditsAi    = (int) $this->settings->getGlobal('billing', 'credits_per_ai_generation', '10');
```

---

## Migration da Tabela Settings

```php
Schema::create('settings', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id')->nullable();
    $table->string('group', 50);
    $table->string('key', 100);
    $table->text('value')->nullable();
    $table->string('type', 20)->default('string'); // string|encrypted|json|boolean
    $table->timestamps();

    $table->unique(['tenant_id', 'group', 'key']);
    $table->index(['group', 'key']);

    $table->foreign('tenant_id')
        ->references('id')
        ->on('tenants')
        ->nullOnDelete();
});
```

---

## Seeds de Settings Globais

```php
// database/seeders/GlobalSettingsSeeder.php
$defaults = [
    ['infobip',     'api_key',              '',                        'encrypted'],
    ['infobip',     'base_url',             'https://api.infobip.com', 'string'],
    ['infobip',     'sender_sms',           'CampaignAI',              'string'],
    ['infobip',     'sender_voice',         '',                        'string'],
    ['infobip',     'sender_email',         '',                        'string'],
    ['ai',          'grok_api_key',         '',                        'encrypted'],
    ['ai',          'grok_model',           'grok-beta',               'string'],
    ['elevenlabs',  'api_key',              '',                        'encrypted'],
    ['elevenlabs',  'model_id',             'eleven_multilingual_v2',  'string'],
    ['elevenlabs',  'cost_per_char',        '0.0003',                  'string'],
    ['elevenlabs',  'sale_per_char',        '0.001',                   'string'],
    ['elevenlabs',  'credits_per_char',     '1',                       'string'],
    ['billing',     'credits_per_sms',      '1',                       'string'],
    ['billing',     'credits_per_voice',    '5',                       'string'],
    ['billing',     'credits_per_email',    '2',                       'string'],
    ['billing',     'credits_per_ai_generation', '10',                 'string'],
];

foreach ($defaults as [$group, $key, $value, $type]) {
    DB::table('settings')->insertOrIgnore([
        'tenant_id'  => null,
        'group'      => $group,
        'key'        => $key,
        'value'      => $value,
        'type'       => $type,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
```

---

## Nunca Fazer

```php
// ❌ Query sem withoutGlobalScopes para settings globais
Setting::whereNull('tenant_id')->where('group', 'infobip')...

// ❌ updateOrInsert com tenant_id null (NULL != NULL no SQL)
Setting::updateOrInsert(['tenant_id' => null, 'group' => 'ai', 'key' => 'key'], [...]);

// ❌ Salvar API key sem encrypt
$settings->upsertGlobal('infobip', 'api_key', $apiKey, 'string');

// ❌ Ler API key sem decrypt
$apiKey = $setting->value; // sem decrypt()

// ❌ Cache key sem prefixo consistente
Cache::forget("infobip_api_key"); // formato errado
```
