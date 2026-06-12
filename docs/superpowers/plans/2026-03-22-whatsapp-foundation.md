# WhatsApp Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add WhatsApp channel to CampaignAI via Meta Cloud API — template campaigns, inbound webhook, conversation models.

**Architecture:** New `WhatsAppService` follows `InfobipService` pattern. Conversation/Message models are separate from CampaignDispatch (bidirectional chat vs one-shot dispatch). Webhook resolves tenant via `whatsapp_phone_numbers` lookup table.

**Tech Stack:** Laravel 12, Vue 3, Tabler UI, Meta Graph API v20.0

**Spec:** `docs/superpowers/specs/2026-03-22-whatsapp-foundation-design.md`

---

## File Structure

### Backend — Create
| File | Responsibility |
|------|---------------|
| `backend/database/migrations/2026_03_22_100000_alter_campaigns_add_whatsapp_type.php` | Add `whatsapp` to campaigns.type enum |
| `backend/database/migrations/2026_03_22_100010_create_whatsapp_phone_numbers_table.php` | Tenant lookup by phone_number_id |
| `backend/database/migrations/2026_03_22_100020_create_conversations_table.php` | Bidirectional conversations |
| `backend/database/migrations/2026_03_22_100030_create_conversation_messages_table.php` | Individual messages |
| `backend/app/Models/WhatsAppPhoneNumber.php` | Model (no tenant scope) |
| `backend/app/Models/Conversation.php` | Model (with tenant scope) |
| `backend/app/Models/ConversationMessage.php` | Model (with tenant scope) |
| `backend/app/Services/WhatsApp/WhatsAppService.php` | Meta Cloud API client |
| `backend/app/Http/Controllers/API/V1/WhatsAppWebhookController.php` | Webhook verify + handle |
| `backend/app/Http/Controllers/API/V1/WhatsAppController.php` | Templates, send text |
| `backend/app/Http/Controllers/API/V1/Admin/WhatsAppSettingsController.php` | Settings CRUD |
| `backend/app/Http/Controllers/API/V1/ConversationController.php` | Conversations CRUD |
| `backend/app/Jobs/ProcessInboundMessageJob.php` | Stub for sub-project 3 |

### Backend — Modify
| File | Change |
|------|--------|
| `backend/config/logging.php:104` | Add `whatsapp` channel after `elevenlabs` |
| `backend/database/seeders/GlobalSettingsSeeder.php:31` | Add `credits_per_whatsapp` |
| `backend/app/Services/CampaignStateMachine.php:57` | Accept `whatsapp` in assertCanDispatch |
| `backend/app/Jobs/ProcessCampaignJob.php:102,151` | Add `whatsapp` case in credit calc + sendToContact |
| `backend/routes/api.php` | Add WhatsApp, webhook, conversation routes |

### Frontend — Create
| File | Responsibility |
|------|---------------|
| `frontend/src/pages/admin/SettingsWhatsApp.vue` | Admin settings page |
| `frontend/src/components/campaigns/steps/Step2WhatsApp.vue` | Template selector + preview |

### Frontend — Modify
| File | Change |
|------|--------|
| `frontend/src/router/index.ts` | Add routes |
| `frontend/src/components/layout/AppSidebar.vue` | Add WhatsApp nav item |
| `frontend/src/pages/campaigns/Create.vue` | Conditional Step 2 for whatsapp |

---

## Task 1: Migrations — campaign type + new tables

**Files:**
- Create: `backend/database/migrations/2026_03_22_100000_alter_campaigns_add_whatsapp_type.php`
- Create: `backend/database/migrations/2026_03_22_100010_create_whatsapp_phone_numbers_table.php`
- Create: `backend/database/migrations/2026_03_22_100020_create_conversations_table.php`
- Create: `backend/database/migrations/2026_03_22_100030_create_conversation_messages_table.php`

- [ ] **Step 1: Create alter_campaigns_add_whatsapp_type migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN type ENUM('sms','voice','email','whatsapp') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE campaigns MODIFY COLUMN type ENUM('sms','voice','email') NOT NULL");
    }
};
```

- [ ] **Step 2: Create whatsapp_phone_numbers migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number_id')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('phone_display')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_phone_numbers');
    }
};
```

- [ ] **Step 3: Create conversations migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('phone');
            $table->string('channel')->default('whatsapp');
            $table->enum('status', ['open', 'bot', 'human', 'closed'])->default('bot');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_message_at']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
```

- [ ] **Step 4: Create conversation_messages migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('tenant_id');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('sender_type', ['contact', 'bot', 'human', 'campaign', 'ai']);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('type')->default('text');
            $table->text('content')->nullable();
            $table->string('media_url', 1024)->nullable();
            $table->string('template_name')->nullable();
            $table->string('external_message_id')->nullable()->unique();
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->index(['conversation_id', 'created_at']);

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('sender_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
```

- [ ] **Step 5: Run migrations**

Run: `cd backend && php artisan migrate`
Expected: 4 migrations executed, no errors

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_03_22_*
git commit -m "feat: add whatsapp migrations - campaign type, phone numbers, conversations"
```

---

## Task 2: Models — WhatsAppPhoneNumber, Conversation, ConversationMessage

**Files:**
- Create: `backend/app/Models/WhatsAppPhoneNumber.php`
- Create: `backend/app/Models/Conversation.php`
- Create: `backend/app/Models/ConversationMessage.php`

- [ ] **Step 1: Create WhatsAppPhoneNumber model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppPhoneNumber extends Model
{
    protected $fillable = ['phone_number_id', 'tenant_id', 'phone_display'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

- [ ] **Step 2: Create Conversation model**

```php
<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use AppliesTenantScope;

    protected $fillable = [
        'tenant_id', 'contact_id', 'phone', 'channel', 'status',
        'assigned_to', 'last_message_at', 'unread_count', 'metadata',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count'    => 'integer',
        'metadata'        => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class);
    }
}
```

- [ ] **Step 3: Create ConversationMessage model**

```php
<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    use AppliesTenantScope;

    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'tenant_id', 'direction', 'sender_type', 'sender_id',
        'type', 'content', 'media_url', 'template_name', 'external_message_id',
        'status', 'error_message', 'metadata', 'created_at', 'sent_at',
        'delivered_at', 'read_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'created_at'   => 'datetime',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
```

- [ ] **Step 4: Verify syntax**

Run: `php -l app/Models/WhatsAppPhoneNumber.php && php -l app/Models/Conversation.php && php -l app/Models/ConversationMessage.php`
Expected: `No syntax errors detected` for all 3

- [ ] **Step 5: Commit**

```bash
git add backend/app/Models/WhatsAppPhoneNumber.php backend/app/Models/Conversation.php backend/app/Models/ConversationMessage.php
git commit -m "feat: add WhatsAppPhoneNumber, Conversation, ConversationMessage models"
```

---

## Task 3: WhatsAppService — Meta Cloud API client

**Files:**
- Create: `backend/app/Services/WhatsApp/WhatsAppService.php`
- Modify: `backend/config/logging.php:104`

- [ ] **Step 1: Add whatsapp logging channel**

In `backend/config/logging.php`, after the `elevenlabs` channel block (line 104), add:

```php
        'whatsapp' => [
            'driver' => 'daily',
            'path' => storage_path('logs/whatsapp.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],
```

- [ ] **Step 2: Create WhatsAppService**

```php
<?php

namespace App\Services\WhatsApp;

use App\Services\SettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private const BASE_URL = 'https://graph.facebook.com';
    private const API_VERSION = 'v20.0';

    public function __construct(private SettingsService $settings) {}

    private function makeClient(int $tenantId): PendingRequest
    {
        $token = $this->settings->get($tenantId, 'whatsapp', 'access_token', '');

        if (empty($token)) {
            throw new \RuntimeException('WhatsApp access_token não configurado para este tenant.');
        }

        return Http::withToken($token)
            ->baseUrl(self::BASE_URL . '/' . self::API_VERSION)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 300);
    }

    private function phoneNumberId(int $tenantId): string
    {
        $id = $this->settings->get($tenantId, 'whatsapp', 'phone_number_id', '');
        if (empty($id)) {
            throw new \RuntimeException('WhatsApp phone_number_id não configurado.');
        }
        return $id;
    }

    public function testConnection(int $tenantId): array
    {
        try {
            $client = $this->makeClient($tenantId);
            $phoneId = $this->phoneNumberId($tenantId);

            $resp = $client->get("/{$phoneId}");

            if ($resp->successful()) {
                $display = $resp->json('display_phone_number', '');
                Log::channel('whatsapp')->info('test.ok', ['tenant' => $tenantId, 'phone' => $display]);
                return ['ok' => true, 'phone_display' => $display, 'error' => null];
            }

            $error = $resp->json('error.message', $resp->body());
            Log::channel('whatsapp')->warning('test.fail', ['tenant' => $tenantId, 'error' => $error]);
            return ['ok' => false, 'phone_display' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('test.exception', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'phone_display' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendTemplate(string $to, string $templateName, string $languageCode, array $components, int $tenantId): array
    {
        try {
            $client = $this->makeClient($tenantId);
            $phoneId = $this->phoneNumberId($tenantId);

            $payload = [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'template',
                'template' => [
                    'name'     => $templateName,
                    'language' => ['code' => $languageCode],
                ],
            ];

            if (!empty($components)) {
                $payload['template']['components'] = $components;
            }

            $resp = $client->post("/{$phoneId}/messages", $payload);

            if ($resp->successful()) {
                $messageId = $resp->json('messages.0.id');
                Log::channel('whatsapp')->info('template.sent', ['to' => $to, 'template' => $templateName, 'wamid' => $messageId]);
                return ['ok' => true, 'message_id' => $messageId, 'error' => null];
            }

            $error = $resp->json('error.message', $resp->body());
            Log::channel('whatsapp')->warning('template.failed', ['to' => $to, 'error' => $error]);
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('template.exception', ['to' => $to, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendText(string $to, string $text, int $tenantId): array
    {
        try {
            $client = $this->makeClient($tenantId);
            $phoneId = $this->phoneNumberId($tenantId);

            $resp = $client->post("/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'to'   => $to,
                'type' => 'text',
                'text' => ['body' => $text],
            ]);

            if ($resp->successful()) {
                $messageId = $resp->json('messages.0.id');
                Log::channel('whatsapp')->info('text.sent', ['to' => $to, 'wamid' => $messageId]);
                return ['ok' => true, 'message_id' => $messageId, 'error' => null];
            }

            $error = $resp->json('error.message', $resp->body());
            Log::channel('whatsapp')->warning('text.failed', ['to' => $to, 'error' => $error]);
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('text.exception', ['to' => $to, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendMedia(string $to, string $mediaType, string $url, ?string $caption, int $tenantId): array
    {
        try {
            $client = $this->makeClient($tenantId);
            $phoneId = $this->phoneNumberId($tenantId);

            $mediaPayload = ['link' => $url];
            if ($caption && in_array($mediaType, ['image', 'video', 'document'])) {
                $mediaPayload['caption'] = $caption;
            }

            $resp = $client->post("/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'to'        => $to,
                'type'      => $mediaType,
                $mediaType  => $mediaPayload,
            ]);

            if ($resp->successful()) {
                $messageId = $resp->json('messages.0.id');
                Log::channel('whatsapp')->info('media.sent', ['to' => $to, 'type' => $mediaType, 'wamid' => $messageId]);
                return ['ok' => true, 'message_id' => $messageId, 'error' => null];
            }

            $error = $resp->json('error.message', $resp->body());
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('media.exception', ['to' => $to, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function getTemplates(int $tenantId): array
    {
        $cacheKey = "whatsapp_templates_{$tenantId}";

        return Cache::remember($cacheKey, 300, function () use ($tenantId) {
            try {
                $client = $this->makeClient($tenantId);
                $wabaId = $this->settings->get($tenantId, 'whatsapp', 'waba_id', '');

                if (empty($wabaId)) {
                    return [];
                }

                $resp = $client->get("/{$wabaId}/message_templates", [
                    'status' => 'APPROVED',
                    'limit'  => 100,
                ]);

                if ($resp->successful()) {
                    return $resp->json('data', []);
                }

                Log::channel('whatsapp')->warning('templates.fetch_failed', ['error' => $resp->body()]);
                return [];
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->error('templates.exception', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }
}
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Services/WhatsApp/WhatsAppService.php && php -l config/logging.php`
Expected: `No syntax errors detected` for both

- [ ] **Step 4: Commit**

```bash
git add backend/app/Services/WhatsApp/WhatsAppService.php backend/config/logging.php
git commit -m "feat: add WhatsAppService - Meta Cloud API client"
```

---

## Task 4: Backend config — seeder + CampaignStateMachine + ProcessCampaignJob

**Files:**
- Modify: `backend/database/seeders/GlobalSettingsSeeder.php:31`
- Modify: `backend/app/Services/CampaignStateMachine.php:55-57`
- Modify: `backend/app/Jobs/ProcessCampaignJob.php:10,36,102-106,151-174`

- [ ] **Step 1: Add credits_per_whatsapp to GlobalSettingsSeeder**

In `GlobalSettingsSeeder.php`, add after line 31 (`credits_per_ai_generation`):

```php
            ['billing',     'credits_per_whatsapp',     '3',                       'string'],
```

- [ ] **Step 2: Update CampaignStateMachine::assertCanDispatch()**

In `CampaignStateMachine.php`, replace line 55:

```php
// old:
if (empty($campaign->content) && empty($campaign->audio_url)) $errors[] = 'Conteúdo da campanha é obrigatório.';
```

With:

```php
if ($campaign->type === 'whatsapp') {
    $settings = $campaign->settings ?? [];
    if (empty($settings['template_name'])) $errors[] = 'Template WhatsApp é obrigatório.';
} elseif (empty($campaign->content) && empty($campaign->audio_url)) {
    $errors[] = 'Conteúdo da campanha é obrigatório.';
}
```

Replace line 57:

```php
// old:
if (!in_array($campaign->type, ['sms', 'voice', 'email'], true)) $errors[] = "Canal '{$campaign->type}' inválido.";
```

With:

```php
if (!in_array($campaign->type, ['sms', 'voice', 'email', 'whatsapp'], true)) $errors[] = "Canal '{$campaign->type}' inválido.";
```

- [ ] **Step 3: Update ProcessCampaignJob**

Add import at top (after line 10):

```php
use App\Services\WhatsApp\WhatsAppService;
```

Add `WhatsAppService` to `handle()` signature (line 38):

```php
public function handle(
    CampaignStateMachine $machine,
    InfobipService $infobip,
    WhatsAppService $whatsapp,
    CreditService $credits,
    SettingsService $settings
): void {
```

Add `whatsapp` case to credit calculation (after line 105):

```php
'whatsapp' => (int) $settings->getGlobal('billing', 'credits_per_whatsapp', '3'),
```

Add `whatsapp` case to `sendToContact()` method (before `default` case, around line 170):

```php
'whatsapp' => [
    $this->sendWhatsAppTemplate($contact, $campaign, app(WhatsAppService::class)),
    $contact->phone,
],
```

Add new method `sendWhatsAppTemplate` before `recordDispatch()`:

```php
private function sendWhatsAppTemplate(Contact $contact, Campaign $campaign, WhatsAppService $whatsapp): array
{
    $settings = $campaign->settings ?? [];
    $templateName = $settings['template_name'] ?? '';
    $language     = $settings['template_language'] ?? 'pt_BR';
    $components   = $settings['components'] ?? [];

    // Substituir variáveis dinâmicas
    $replacements = [
        '{nome}'     => $contact->name ?? '',
        '{telefone}' => $contact->phone ?? '',
        '{email}'    => $contact->email ?? '',
    ];

    $components = json_decode(
        str_replace(array_keys($replacements), array_values($replacements), json_encode($components)),
        true
    );

    $result = $whatsapp->sendTemplate($contact->phone, $templateName, $language, $components, $this->tenantId);

    // Criar/reabrir conversa + registrar mensagem
    if ($result['ok']) {
        $conversation = \App\Models\Conversation::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenantId, 'phone' => $contact->phone],
            [
                'contact_id'      => $contact->id,
                'channel'         => 'whatsapp',
                'status'          => 'bot',
                'last_message_at' => now(),
            ]
        );

        \App\Models\ConversationMessage::create([
            'conversation_id'    => $conversation->id,
            'tenant_id'          => $this->tenantId,
            'direction'          => 'outbound',
            'sender_type'        => 'campaign',
            'type'               => 'template',
            'template_name'      => $templateName,
            'content'            => $campaign->content,
            'external_message_id'=> $result['message_id'],
            'status'             => 'sent',
            'created_at'         => now(),
            'sent_at'            => now(),
            'metadata'           => ['campaign_id' => $campaign->id],
        ]);
    }

    return $result;
}
```

- [ ] **Step 4: Verify syntax**

Run: `php -l database/seeders/GlobalSettingsSeeder.php && php -l app/Services/CampaignStateMachine.php && php -l app/Jobs/ProcessCampaignJob.php`
Expected: `No syntax errors detected` for all 3

- [ ] **Step 5: Run seeder**

Run: `php artisan db:seed --class=GlobalSettingsSeeder`
Expected: Seeding completed

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/GlobalSettingsSeeder.php backend/app/Services/CampaignStateMachine.php backend/app/Jobs/ProcessCampaignJob.php
git commit -m "feat: integrate whatsapp into campaign pipeline - credits, state machine, dispatch"
```

---

## Task 5: WhatsAppWebhookController

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/WhatsAppWebhookController.php`
- Create: `backend/app/Jobs/ProcessInboundMessageJob.php`

- [ ] **Step 1: Create ProcessInboundMessageJob (stub)**

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $messageId,
        public int $tenantId
    ) {}

    public function handle(): void
    {
        // Stub — sub-projeto 3 implementa lógica de IA/chatbot
        Log::channel('whatsapp')->info('inbound.stub', [
            'conversation_id' => $this->conversationId,
            'message_id'      => $this->messageId,
            'tenant_id'       => $this->tenantId,
        ]);
    }
}
```

- [ ] **Step 2: Create WhatsAppWebhookController**

```php
<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundMessageJob;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // Buscar verify_token global (webhook é por App, não por tenant)
        $expectedToken = $this->settings->getGlobal('whatsapp', 'verify_token', '');

        if ($mode === 'subscribe' && !empty($expectedToken) && hash_equals($expectedToken, $token ?? '')) {
            Log::channel('whatsapp')->info('webhook.verified');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::channel('whatsapp')->warning('webhook.verify_failed', ['mode' => $mode, 'ip' => $request->ip()]);
        return response('Forbidden', 403);
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->validateSignature($request)) {
            Log::channel('whatsapp')->warning('webhook.invalid_signature', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();
        Log::channel('whatsapp')->debug('webhook.received', ['payload' => $payload]);

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                if (! $phoneNumberId) continue;

                $phoneNumber = WhatsAppPhoneNumber::where('phone_number_id', $phoneNumberId)->first();
                if (! $phoneNumber) {
                    Log::channel('whatsapp')->debug('webhook.unknown_phone', ['phone_number_id' => $phoneNumberId]);
                    continue;
                }

                $tenantId = $phoneNumber->tenant_id;

                foreach ($value['messages'] ?? [] as $message) {
                    $this->processInboundMessage($message, $tenantId, $value['contacts'] ?? []);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->processStatusUpdate($status, $tenantId);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function processInboundMessage(array $message, int $tenantId, array $contacts): void
    {
        $waId = $message['from'] ?? null;
        if (! $waId) return;

        $contactName = collect($contacts)->firstWhere('wa_id', $waId)['profile']['name'] ?? null;

        // Buscar ou criar Contact
        $contact = Contact::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('phone', $waId)
            ->first();

        if (! $contact) {
            // Criar contato em uma lista padrão (ou sem lista)
            $contact = Contact::forceCreate([
                'tenant_id' => $tenantId,
                'phone'     => $waId,
                'name'      => $contactName,
                'status'    => 'active',
            ]);
        }

        // Buscar ou criar Conversation
        $conversation = Conversation::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'phone' => $waId],
            [
                'contact_id'      => $contact->id,
                'channel'         => 'whatsapp',
                'last_message_at' => now(),
            ]
        );

        // Incrementar unread_count
        $conversation->increment('unread_count');

        // Se conversa estava fechada, reabrir como bot
        if ($conversation->status === 'closed') {
            $conversation->update(['status' => 'bot']);
        }

        // Determinar tipo de mensagem
        $type    = $message['type'] ?? 'text';
        $content = null;
        $mediaUrl = null;

        switch ($type) {
            case 'text':
                $content = $message['text']['body'] ?? '';
                break;
            case 'image':
            case 'video':
            case 'audio':
            case 'document':
                $content = $message[$type]['caption'] ?? null;
                $mediaUrl = $message[$type]['id'] ?? null; // Media ID, precisa download separado
                break;
            case 'location':
                $lat = $message['location']['latitude'] ?? 0;
                $lng = $message['location']['longitude'] ?? 0;
                $content = "{$lat},{$lng}";
                break;
            case 'reaction':
                $content = $message['reaction']['emoji'] ?? '';
                break;
        }

        $conversationMessage = ConversationMessage::forceCreate([
            'conversation_id'     => $conversation->id,
            'tenant_id'           => $tenantId,
            'direction'           => 'inbound',
            'sender_type'         => 'contact',
            'type'                => $type,
            'content'             => $content,
            'media_url'           => $mediaUrl,
            'external_message_id' => $message['id'] ?? null,
            'status'              => 'delivered',
            'created_at'          => now(),
        ]);

        // Se conversa em modo bot, despachar para processamento
        if ($conversation->status === 'bot') {
            ProcessInboundMessageJob::dispatch(
                $conversation->id,
                $conversationMessage->id,
                $tenantId
            );
        }

        Log::channel('whatsapp')->info('inbound.processed', [
            'tenant'       => $tenantId,
            'from'         => $waId,
            'type'         => $type,
            'conversation' => $conversation->id,
        ]);
    }

    private function processStatusUpdate(array $status, int $tenantId): void
    {
        $wamid     = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;

        if (! $wamid || ! $newStatus) return;

        $msg = ConversationMessage::withoutGlobalScopes()
            ->where('external_message_id', $wamid)
            ->first();

        if (! $msg) return;

        $updates = ['status' => $newStatus];

        switch ($newStatus) {
            case 'sent':
                $updates['sent_at'] = now();
                break;
            case 'delivered':
                $updates['delivered_at'] = now();
                break;
            case 'read':
                $updates['read_at'] = now();
                break;
            case 'failed':
                $updates['error_message'] = $status['errors'][0]['title'] ?? 'Delivery failed';
                break;
        }

        $msg->update($updates);

        // Se veio de campanha, atualizar CampaignDispatch também
        if ($msg->sender_type === 'campaign') {
            $campaignId = $msg->metadata['campaign_id'] ?? null;
            if ($campaignId) {
                \App\Models\CampaignDispatch::withoutGlobalScopes()
                    ->where('external_message_id', $wamid)
                    ->update(array_intersect_key($updates, array_flip(['status', 'delivered_at', 'error_message'])));
            }
        }

        Log::channel('whatsapp')->debug('status.updated', ['wamid' => $wamid, 'status' => $newStatus]);
    }

    private function validateSignature(Request $request): bool
    {
        $appSecret = $this->settings->getGlobal('whatsapp', 'app_secret', '');

        if (empty($appSecret)) {
            return true; // Se não configurado, aceita (desenvolvimento)
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $expected  = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Http/Controllers/API/V1/WhatsAppWebhookController.php && php -l app/Jobs/ProcessInboundMessageJob.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/WhatsAppWebhookController.php backend/app/Jobs/ProcessInboundMessageJob.php
git commit -m "feat: add WhatsApp webhook controller + inbound message stub job"
```

---

## Task 6: WhatsAppController + WhatsAppSettingsController + ConversationController

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/WhatsAppController.php`
- Create: `backend/app/Http/Controllers/API/V1/Admin/WhatsAppSettingsController.php`
- Create: `backend/app/Http/Controllers/API/V1/ConversationController.php`

- [ ] **Step 1: Create WhatsAppController**

```php
<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function templates()
    {
        $tenantId = auth()->user()->tenant_id;
        $templates = $this->whatsapp->getTemplates($tenantId);
        return ApiResponse::success($templates);
    }

    public function templatePreview(string $name)
    {
        $tenantId = auth()->user()->tenant_id;
        $templates = $this->whatsapp->getTemplates($tenantId);
        $template = collect($templates)->firstWhere('name', $name);

        if (! $template) {
            return ApiResponse::error('Template não encontrado', [], 404);
        }

        return ApiResponse::success($template);
    }

    public function sendText(Request $request)
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
            'text'            => ['required', 'string', 'min:1', 'max:4096'],
        ]);

        $conversation = Conversation::findOrFail($data['conversation_id']);
        $tenantId = auth()->user()->tenant_id;

        $result = $this->whatsapp->sendText($conversation->phone, $data['text'], $tenantId);

        if (! $result['ok']) {
            return ApiResponse::error('Falha ao enviar mensagem: ' . ($result['error'] ?? 'Erro desconhecido'), [], 422);
        }

        $message = ConversationMessage::create([
            'conversation_id'     => $conversation->id,
            'tenant_id'           => $tenantId,
            'direction'           => 'outbound',
            'sender_type'         => 'human',
            'sender_id'           => auth()->id(),
            'type'                => 'text',
            'content'             => $data['text'],
            'external_message_id' => $result['message_id'],
            'status'              => 'sent',
            'created_at'          => now(),
            'sent_at'             => now(),
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'unread_count'    => 0,
        ]);

        return ApiResponse::success($message, 'Mensagem enviada');
    }
}
```

- [ ] **Step 2: Create WhatsAppSettingsController**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\WhatsAppPhoneNumber;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WhatsAppSettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private WhatsAppService $whatsapp) {}

    public function index(Request $request)
    {
        $tenantId = $request->query('tenant_id', auth()->user()->tenant_id);

        return ApiResponse::success([
            'has_access_token' => !empty($this->settings->get($tenantId, 'whatsapp', 'access_token', '')),
            'phone_number_id'  => $this->settings->get($tenantId, 'whatsapp', 'phone_number_id', ''),
            'waba_id'          => $this->settings->get($tenantId, 'whatsapp', 'waba_id', ''),
            'verify_token'     => $this->settings->getGlobal('whatsapp', 'verify_token', ''),
        ]);
    }

    public function update(Request $request)
    {
        $tenantId = $request->input('tenant_id', auth()->user()->tenant_id);

        $data = $request->validate([
            'phone_number_id' => ['nullable', 'string'],
            'waba_id'         => ['nullable', 'string'],
            'access_token'    => ['nullable', 'string'],
            'verify_token'    => ['nullable', 'string'],
            'app_secret'      => ['nullable', 'string'],
        ]);

        $map = [
            'phone_number_id' => 'string',
            'waba_id'         => 'string',
            'access_token'    => 'encrypted',
            'app_secret'      => 'encrypted',
        ];

        foreach ($map as $key => $type) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '********') {
                $this->settings->upsert($tenantId, 'whatsapp', $key, $data[$key], $type);
            }
        }

        // verify_token é global (webhook é por App)
        if (array_key_exists('verify_token', $data) && $data['verify_token'] !== null) {
            $this->settings->upsertGlobal('whatsapp', 'verify_token', $data['verify_token'], 'string');
        }

        return ApiResponse::success([], 'Atualizado');
    }

    public function test(Request $request)
    {
        $tenantId = $request->input('tenant_id', auth()->user()->tenant_id);
        $result = $this->whatsapp->testConnection($tenantId);

        if (!$result['ok']) {
            return ApiResponse::error('Falha no teste de conexão WhatsApp: ' . ($result['error'] ?? ''), [], 422);
        }

        // Salvar phone number lookup para webhook
        if ($result['phone_display']) {
            $phoneNumberId = $this->settings->get($tenantId, 'whatsapp', 'phone_number_id', '');
            if ($phoneNumberId) {
                WhatsAppPhoneNumber::updateOrCreate(
                    ['phone_number_id' => $phoneNumberId],
                    ['tenant_id' => $tenantId, 'phone_display' => $result['phone_display']]
                );
            }
        }

        return ApiResponse::success($result, 'Conexão OK');
    }

    public function syncTemplates(Request $request)
    {
        $tenantId = $request->input('tenant_id', auth()->user()->tenant_id);

        Cache::forget("whatsapp_templates_{$tenantId}");
        $templates = $this->whatsapp->getTemplates($tenantId);

        return ApiResponse::success([
            'count'     => count($templates),
            'templates' => $templates,
        ], 'Templates sincronizados');
    }
}
```

- [ ] **Step 3: Create ConversationController**

```php
<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:open,bot,human,closed'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $q = Conversation::query()->with('contact:id,name,phone,email');

        if (!empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }
        if (!empty($validated['search'])) {
            $s = $validated['search'];
            $q->where(function ($w) use ($s) {
                $w->where('phone', 'like', "%{$s}%")
                  ->orWhereHas('contact', function ($cq) use ($s) {
                      $cq->where('name', 'like', "%{$s}%");
                  });
            });
        }

        $items = $q->orderByDesc('last_message_at')->paginate(20);
        return ApiResponse::paginated($items, 'OK');
    }

    public function show(int $id)
    {
        $conversation = Conversation::with('contact:id,name,phone,email')->findOrFail($id);

        $messages = ConversationMessage::where('conversation_id', $id)
            ->orderByDesc('created_at')
            ->paginate(50);

        // Zerar unread
        $conversation->update(['unread_count' => 0]);

        return ApiResponse::success([
            'conversation' => $conversation,
            'messages'     => $messages->items(),
            'pagination'   => [
                'total'        => $messages->total(),
                'current_page' => $messages->currentPage(),
                'last_page'    => $messages->lastPage(),
            ],
        ]);
    }

    public function sendMessage(int $id, Request $request)
    {
        $conversation = Conversation::findOrFail($id);

        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:4096'],
        ]);

        $whatsapp = app(\App\Services\WhatsApp\WhatsAppService::class);
        $tenantId = auth()->user()->tenant_id;

        $result = $whatsapp->sendText($conversation->phone, $data['text'], $tenantId);

        if (! $result['ok']) {
            return ApiResponse::error('Falha ao enviar: ' . ($result['error'] ?? ''), [], 422);
        }

        $message = ConversationMessage::create([
            'conversation_id'     => $conversation->id,
            'tenant_id'           => $tenantId,
            'direction'           => 'outbound',
            'sender_type'         => 'human',
            'sender_id'           => auth()->id(),
            'type'                => 'text',
            'content'             => $data['text'],
            'external_message_id' => $result['message_id'],
            'status'              => 'sent',
            'created_at'          => now(),
            'sent_at'             => now(),
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'unread_count'    => 0,
            'status'          => 'human', // Operador assumiu
        ]);

        return ApiResponse::success($message, 'Enviada');
    }
}
```

- [ ] **Step 4: Verify syntax**

Run: `php -l app/Http/Controllers/API/V1/WhatsAppController.php && php -l app/Http/Controllers/API/V1/Admin/WhatsAppSettingsController.php && php -l app/Http/Controllers/API/V1/ConversationController.php`
Expected: `No syntax errors detected` for all 3

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/WhatsAppController.php backend/app/Http/Controllers/API/V1/Admin/WhatsAppSettingsController.php backend/app/Http/Controllers/API/V1/ConversationController.php
git commit -m "feat: add WhatsApp, WhatsAppSettings, and Conversation controllers"
```

---

## Task 7: Routes — register all new endpoints

**Files:**
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Add imports and routes**

Add imports at top of file (after line 19):

```php
use App\Http\Controllers\API\V1\WhatsAppController;
use App\Http\Controllers\API\V1\WhatsAppWebhookController;
use App\Http\Controllers\API\V1\Admin\WhatsAppSettingsController;
use App\Http\Controllers\API\V1\ConversationController;
```

Add webhook routes after Infobip webhook (after line 28):

```php
    // WhatsApp webhooks (Meta Cloud API)
    Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
        ->withoutMiddleware(['auth:sanctum']);
    Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
        ->withoutMiddleware(['auth:sanctum']);
```

Add authenticated routes inside the `auth:sanctum` group (after campaigns block, around line 83):

```php
        // WhatsApp
        Route::get('whatsapp/templates',        [WhatsAppController::class, 'templates']);
        Route::get('whatsapp/templates/{name}', [WhatsAppController::class, 'templatePreview']);
        Route::post('whatsapp/send-text',       [WhatsAppController::class, 'sendText']);

        // Conversas
        Route::get('conversations',               [ConversationController::class, 'index']);
        Route::get('conversations/{id}',          [ConversationController::class, 'show']);
        Route::post('conversations/{id}/messages', [ConversationController::class, 'sendMessage']);
```

Add admin routes inside the `superadmin` group (after ElevenLabs settings, around line 98):

```php
            Route::get('settings/whatsapp', [WhatsAppSettingsController::class, 'index']);
            Route::put('settings/whatsapp', [WhatsAppSettingsController::class, 'update']);
            Route::post('settings/whatsapp/test', [WhatsAppSettingsController::class, 'test']);
            Route::post('settings/whatsapp/sync-templates', [WhatsAppSettingsController::class, 'syncTemplates']);
```

Also update `CampaignsController` validation. In `store()` method, change the type validation:

```php
'type' => ['required','in:sms,voice,email,whatsapp'],
```

- [ ] **Step 2: Verify routes load**

Run: `php artisan route:list --path=v1 2>&1 | grep -E "(whatsapp|conversation)" | wc -l`
Expected: 10 routes (2 webhook + 3 whatsapp + 3 conversations + 4 admin settings - but grep should find ~10)

- [ ] **Step 3: Commit**

```bash
git add backend/routes/api.php backend/app/Http/Controllers/API/V1/CampaignsController.php
git commit -m "feat: register all WhatsApp and conversation routes"
```

---

## Task 8: Frontend — SettingsWhatsApp admin page

**Files:**
- Create: `frontend/src/pages/admin/SettingsWhatsApp.vue`
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/components/layout/AppSidebar.vue`

- [ ] **Step 1: Create SettingsWhatsApp.vue**

Follow exact pattern from `SettingsInfobip.vue` (lines 1-110):

```vue
<template>
  <div class="page-header d-print-none">
    <div class="container-xl"><h2 class="page-title">WhatsApp Settings</h2></div>
  </div>
  <div class="card">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">Phone Number ID</label>
        <input class="form-control" v-model="form.phone_number_id" placeholder="ID do número no Meta" />
      </div>
      <div class="mb-3">
        <label class="form-label">WABA ID</label>
        <input class="form-control" v-model="form.waba_id" placeholder="WhatsApp Business Account ID" />
      </div>
      <div class="mb-3">
        <label class="form-label">Access Token</label>
        <input type="password" class="form-control" v-model="form.access_token" placeholder="********" />
      </div>
      <div class="mb-3">
        <label class="form-label">Verify Token <span class="text-muted">(global, para validar webhook)</span></label>
        <input class="form-control" v-model="form.verify_token" placeholder="Token customizado" />
      </div>
      <div class="mb-3">
        <label class="form-label">App Secret <span class="text-muted">(para validar assinatura)</span></label>
        <input type="password" class="form-control" v-model="form.app_secret" placeholder="********" />
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
      <div>
        <button class="btn btn-ghost-secondary me-2" @click="test" :disabled="isLoading">
          <span v-if="testing" class="spinner-border spinner-border-sm me-2"></span>
          Testar conexão
        </button>
        <button class="btn btn-ghost-info" @click="syncTemplates" :disabled="isLoading">
          <span v-if="syncing" class="spinner-border spinner-border-sm me-2"></span>
          Sincronizar templates
        </button>
      </div>
      <button class="btn btn-primary" @click="save" :disabled="isLoading">
        <span v-if="isLoading && !testing && !syncing" class="spinner-border spinner-border-sm me-2"></span>
        Salvar
      </button>
    </div>
  </div>

  <div v-if="templates.length" class="card mt-3">
    <div class="card-header"><div class="card-title">Templates aprovados ({{ templates.length }})</div></div>
    <div class="table-responsive">
      <table class="table card-table table-vcenter">
        <thead><tr>
          <th>Nome</th><th>Idioma</th><th>Categoria</th><th>Status</th>
        </tr></thead>
        <tbody>
          <tr v-for="t in templates" :key="t.name + t.language">
            <td class="fw-bold">{{ t.name }}</td>
            <td>{{ t.language }}</td>
            <td>{{ t.category }}</td>
            <td><span class="badge bg-green">{{ t.status }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, put, post } = useApi()
const toast = useToast()
const isLoading = ref(false)
const testing = ref(false)
const syncing = ref(false)

const form = ref({ phone_number_id: '', waba_id: '', access_token: '', verify_token: '', app_secret: '' })
const templates = ref<any[]>([])

const load = async () => {
  isLoading.value = true
  try {
    const data = await get<any>('/admin/settings/whatsapp')
    form.value.phone_number_id = data?.phone_number_id ?? ''
    form.value.waba_id = data?.waba_id ?? ''
    form.value.verify_token = data?.verify_token ?? ''
    if (data?.has_access_token) form.value.access_token = '********'
  } finally { isLoading.value = false }
}

const save = async () => {
  isLoading.value = true
  try {
    const payload = { ...form.value }
    if (payload.access_token === '********') delete (payload as any).access_token
    if (payload.app_secret === '********' || !payload.app_secret) delete (payload as any).app_secret
    await put('/admin/settings/whatsapp', payload)
    toast.success('Salvo!')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro ao salvar') }
  finally { isLoading.value = false }
}

const test = async () => {
  testing.value = true; isLoading.value = true
  try {
    await post('/admin/settings/whatsapp/test', {})
    toast.success('Conexão OK')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Falha no teste') }
  finally { testing.value = false; isLoading.value = false }
}

const syncTemplates = async () => {
  syncing.value = true; isLoading.value = true
  try {
    const res = await post<any>('/admin/settings/whatsapp/sync-templates', {})
    templates.value = res?.templates ?? []
    toast.success(`${res?.count ?? 0} templates sincronizados`)
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro ao sincronizar') }
  finally { syncing.value = false; isLoading.value = false }
}

onMounted(load)
</script>
```

- [ ] **Step 2: Add route in router/index.ts**

After the ElevenLabs settings route (line 37), add:

```ts
  {
    path: '/admin/settings/whatsapp',
    component: () => import('@/pages/admin/SettingsWhatsApp.vue'),
    meta: { superadmin: true, title: 'Settings • WhatsApp' },
  },
  {
    path: '/conversations',
    component: () => import('@/pages/dashboard/Index.vue'), // placeholder until sub-project 2
    meta: { title: 'Conversas' },
  },
```

- [ ] **Step 3: Add WhatsApp nav items in AppSidebar.vue**

After the "Relatórios" nav item (line 37), add:

```html
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/conversations') }" @click.prevent="go('/conversations')">
            <span class="nav-link-icon"><i class="ti ti-brand-whatsapp"></i></span>
            <span class="nav-link-title">Conversas</span>
          </a>
        </li>
```

In the admin dropdown (after ElevenLabs item, line 68), add:

```html
            <a class="dropdown-item" :class="{ active: isActive('/admin/settings/whatsapp') }" @click.prevent="go('/admin/settings/whatsapp')">
              <i class="ti ti-brand-whatsapp me-2"></i> WhatsApp
            </a>
```

- [ ] **Step 4: Build frontend**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/admin/SettingsWhatsApp.vue frontend/src/router/index.ts frontend/src/components/layout/AppSidebar.vue
git commit -m "feat: add WhatsApp settings page + sidebar navigation"
```

---

## Task 9: Frontend — Step2WhatsApp template selector

**Files:**
- Create: `frontend/src/components/campaigns/steps/Step2WhatsApp.vue`
- Modify: `frontend/src/pages/campaigns/Create.vue`

- [ ] **Step 1: Create Step2WhatsApp.vue**

```vue
<template>
  <div>
    <h3 class="mb-3">Selecionar Template WhatsApp</h3>

    <div v-if="loading" class="text-center py-4">
      <div class="spinner-border text-primary"></div>
      <p class="text-muted mt-2">Carregando templates...</p>
    </div>

    <div v-else-if="!templates.length" class="empty py-4">
      <div class="empty-icon"><i class="ti ti-brand-whatsapp"></i></div>
      <p class="empty-title">Nenhum template aprovado</p>
      <p class="empty-subtitle text-muted">Configure o WhatsApp no painel Admin e sincronize os templates.</p>
    </div>

    <template v-else>
      <div class="mb-3">
        <label class="form-label">Template *</label>
        <select class="form-select" v-model="selectedName" @change="onSelectTemplate">
          <option value="">Selecione um template...</option>
          <option v-for="t in templates" :key="t.name" :value="t.name">
            {{ t.name }} ({{ t.language }}) — {{ t.category }}
          </option>
        </select>
      </div>

      <div v-if="selected" class="card">
        <div class="card-header">
          <div class="card-title">Preview: {{ selected.name }}</div>
          <span class="badge bg-green ms-auto">{{ selected.status }}</span>
        </div>
        <div class="card-body">
          <div v-for="(comp, ci) in selected.components" :key="ci" class="mb-3">
            <div class="fw-bold text-uppercase small text-muted mb-1">{{ comp.type }}</div>

            <div v-if="comp.type === 'BODY'" class="bg-light rounded p-3" style="white-space:pre-wrap">
              {{ previewBody(comp) }}
            </div>
            <div v-else-if="comp.type === 'HEADER'" class="fw-bold">
              {{ comp.text ?? comp.format }}
            </div>
            <div v-else-if="comp.type === 'FOOTER'" class="text-muted small">
              {{ comp.text }}
            </div>
            <div v-else-if="comp.type === 'BUTTONS'">
              <div v-for="(btn, bi) in comp.buttons" :key="bi" class="btn btn-sm btn-outline-primary me-2 mt-1">
                {{ btn.text }}
              </div>
            </div>
          </div>

          <div v-if="bodyParams.length" class="mt-3 pt-3 border-top">
            <h4 class="mb-2">Variáveis do template</h4>
            <div v-for="(param, pi) in bodyParams" :key="pi" class="row mb-2 align-items-center">
              <div class="col-auto">
                <span class="badge bg-azure">{{ '{{' + (pi + 1) + '}}' }}</span>
              </div>
              <div class="col">
                <select class="form-select form-select-sm" v-model="paramValues[pi]" @change="emitSettings">
                  <option value="">Selecione...</option>
                  <option value="{nome}">Nome do contato</option>
                  <option value="{telefone}">Telefone</option>
                  <option value="{email}">Email</option>
                  <option value="__custom__">Texto fixo...</option>
                </select>
                <input v-if="paramValues[pi] === '__custom__'" class="form-control form-control-sm mt-1"
                       v-model="customValues[pi]" placeholder="Digite o valor fixo" @input="emitSettings" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ modelValue: Record<string, any> | null }>()
const emit = defineEmits<{
  'update:modelValue': [value: Record<string, any> | null]
  'update:valid': [valid: boolean]
}>()

const { get } = useApi()
const toast = useToast()

const loading = ref(false)
const templates = ref<any[]>([])
const selectedName = ref('')
const selected = ref<any>(null)
const paramValues = ref<string[]>([])
const customValues = ref<string[]>([])

const bodyParams = computed(() => {
  if (!selected.value) return []
  const body = selected.value.components?.find((c: any) => c.type === 'BODY')
  if (!body?.text) return []
  const matches = body.text.match(/\{\{\d+\}\}/g)
  return matches ?? []
})

function previewBody(comp: any): string {
  let text = comp.text ?? ''
  bodyParams.value.forEach((_, i) => {
    const val = paramValues.value[i] === '__custom__' ? (customValues.value[i] || `{{${i+1}}}`) : (paramValues.value[i] || `{{${i+1}}}`)
    text = text.replace(`{{${i+1}}}`, val)
  })
  return text
}

function onSelectTemplate() {
  selected.value = templates.value.find(t => t.name === selectedName.value) ?? null
  paramValues.value = bodyParams.value.map(() => '')
  customValues.value = bodyParams.value.map(() => '')
  emitSettings()
}

function emitSettings() {
  if (!selected.value) {
    emit('update:modelValue', null)
    emit('update:valid', false)
    return
  }

  const parameters = paramValues.value.map((v, i) => ({
    type: 'text',
    text: v === '__custom__' ? (customValues.value[i] || '') : (v || ''),
  }))

  const hasAllParams = bodyParams.value.length === 0 || parameters.every(p => p.text !== '')

  const settings = {
    template_name: selected.value.name,
    template_language: selected.value.language,
    components: parameters.length ? [{
      type: 'body',
      parameters,
    }] : [],
  }

  emit('update:modelValue', settings)
  emit('update:valid', hasAllParams)
}

onMounted(async () => {
  loading.value = true
  try {
    templates.value = await get<any[]>('/whatsapp/templates') ?? []
  } catch (e: any) {
    toast.error('Erro ao carregar templates WhatsApp')
  } finally {
    loading.value = false
  }

  // Restore previous selection if editing
  if (props.modelValue?.template_name) {
    selectedName.value = props.modelValue.template_name
    onSelectTemplate()
  }
})
</script>
```

- [ ] **Step 2: Wire Step2WhatsApp into Create.vue**

Read `Create.vue` to find the step 2 section, then add conditional rendering. Add import:

```ts
import Step2WhatsApp from '@/components/campaigns/steps/Step2WhatsApp.vue'
```

In the step 2 `<div v-show="currentStep === 2">` block, wrap existing content in `v-if="form.type !== 'whatsapp'"` and add the WhatsApp component:

```html
<div v-show="currentStep === 2">
  <Step2WhatsApp
    v-if="form.type === 'whatsapp'"
    v-model="whatsappSettings"
    @update:valid="whatsappValid = $event"
  />
  <!-- existing Step2 content wrapped in v-else -->
  <div v-else>
    <!-- ... existing tabs/content for sms/voice/email ... -->
  </div>
</div>
```

Add refs:

```ts
const whatsappSettings = ref<Record<string, any> | null>(null)
const whatsappValid = ref(false)
```

Before saving/advancing from step 2, merge whatsapp settings into campaign:

```ts
// In the save/advance logic for step 2:
if (form.value.type === 'whatsapp' && whatsappSettings.value) {
  await put(`/campaigns/${campaignId.value}`, {
    settings: whatsappSettings.value,
    content: `Template: ${whatsappSettings.value.template_name}`,
  })
}
```

- [ ] **Step 3: Build and verify**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/campaigns/steps/Step2WhatsApp.vue frontend/src/pages/campaigns/Create.vue
git commit -m "feat: add Step2WhatsApp template selector in campaign wizard"
```

---

## Task 10: Final verification

- [ ] **Step 1: PHP syntax check all new backend files**

Run: `cd backend && find app database -name '*.php' -newer artisan | xargs -I{} php -l {} 2>&1 | grep -v "No syntax errors"`
Expected: No output (all files pass)

- [ ] **Step 2: Route list check**

Run: `php artisan route:list --path=v1 2>&1 | wc -l`
Expected: ~80+ routes, no errors

- [ ] **Step 3: Migration status check**

Run: `php artisan migrate:status 2>&1 | grep whatsapp`
Expected: All 4 WhatsApp migrations show `Ran`

- [ ] **Step 4: Frontend build**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 5: Manual smoke test checklist**

1. Login → Admin → Settings → WhatsApp page loads
2. WhatsApp appears in sidebar nav
3. Campaign wizard → select "WhatsApp" channel → Step 2 shows template selector
4. Conversations nav item visible in sidebar
5. `POST /api/v1/webhooks/whatsapp` returns `{"ok":true}` (empty payload)
6. `GET /api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=test` returns 403
