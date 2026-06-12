# Sprint 0: Production Blockers Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all 19 critical/high production blockers identified in the audit, making the system safe and functional for production deploy.

**Architecture:** Fixes are grouped by domain (backend security, frontend validation, UX). Each task is independent and can be parallelized. Backend fixes use defense-in-depth (explicit tenant checks + global scopes). Frontend fixes add proper validation at each wizard step.

**Tech Stack:** Laravel 12 (PHP), Vue 3 + TypeScript, Tabler CSS, DOMPurify

---

## File Map

### Backend Files to Modify
- `backend/app/Http/Controllers/API/V1/CampaignsController.php` — Add ensureTenantOwns to update()
- `backend/app/Http/Controllers/API/V1/ContactsController.php` — Add ensureTenantOwns to update(), destroy()
- `backend/app/Http/Controllers/API/V1/ConversationController.php` — Add ChecksTenant trait
- `backend/app/Http/Controllers/API/V1/FunnelController.php` — Add ChecksTenant trait
- `backend/app/Http/Controllers/API/V1/AudioGenerationController.php` — Add ChecksTenant trait
- `backend/app/Http/Controllers/API/V1/ImportController.php` — Add ensureTenantOwns to status()
- `backend/app/Http/Controllers/API/V1/InfobipWhatsAppWebhookController.php` — Add HMAC validation
- `backend/app/Http/Controllers/API/V1/WebhookController.php` — Fix ltrim bug
- `backend/app/Console/Commands/DispatchScheduledCampaigns.php` — Fix $lock variable
- `backend/app/Services/CampaignStateMachine.php` — Require audio_url for voice
- `backend/app/Services/Billing/CreditService.php` — Implement atomic reserve
- `backend/app/Http/Controllers/API/V1/AuthController.php` — Wrap register in DB::transaction
- `backend/bootstrap/app.php` — Add exception handler
- `backend/routes/api.php` — Move closures to controllers

### Backend Files to Create
- `backend/app/Http/Controllers/API/V1/ChannelsController.php` — Extracted from route closure
- `backend/app/Http/Controllers/API/V1/PublicPlansController.php` — Extracted from route closure

### Frontend Files to Modify
- `frontend/src/pages/auth/Register.vue` — Fix token/user handling
- `frontend/src/stores/auth.ts` — Fix setUser method
- `frontend/src/pages/campaigns/Create.vue` — Wizard validation, stepper labels, confirmation modal, contactsTotal
- `frontend/src/components/campaigns/steps/Step2WhatsApp.vue` — Add DOMPurify
- `frontend/src/components/campaigns/steps/VoiceStudio.vue` — Restructure into sub-steps
- `frontend/src/pages/funnels/Editor.vue` — Mobile fallback

---

## Task 1: Fix Registration Bug (C-01)

**Files:**
- Modify: `frontend/src/stores/auth.ts:54-56`
- Modify: `frontend/src/pages/auth/Register.vue:120-121`

- [ ] **Step 1: Fix setUser in auth store to accept login/register response**

In `frontend/src/stores/auth.ts`, replace the `setUser` function:

```typescript
function setUser(userData: any) {
  user.value = userData
}
```

with:

```typescript
function setUser(userData: any) {
  // Handle both direct user object and {user, token} response
  if (userData?.user && userData?.token) {
    user.value = userData.user
    token.value = userData.token
  } else {
    user.value = userData
  }
}
```

- [ ] **Step 2: Verify Register.vue calls setUser correctly**

In `frontend/src/pages/auth/Register.vue`, line 120-121 already does:
```typescript
auth.setUser({ user: res.user, token: res.token })
```

With the fix in Step 1, this will now correctly set both `user` and `token`. No change needed in Register.vue.

- [ ] **Step 3: Test manually**

1. Go to `/register`
2. Fill form with valid data
3. Verify: after submit, user is redirected to `/dashboard` (not back to `/login`)
4. Verify: `auth.user.name` shows correctly in sidebar
5. Verify: page refresh keeps the session

- [ ] **Step 4: Commit**

```bash
git add frontend/src/stores/auth.ts
git commit -m "fix: registration bug — token and user now saved correctly in auth store"
```

---

## Task 2: Backend Security — ensureTenantOwns in All Controllers (C-04)

**Files:**
- Modify: `backend/app/Http/Controllers/API/V1/CampaignsController.php:90`
- Modify: `backend/app/Http/Controllers/API/V1/ContactsController.php:75,88`
- Modify: `backend/app/Http/Controllers/API/V1/ConversationController.php`
- Modify: `backend/app/Http/Controllers/API/V1/FunnelController.php`
- Modify: `backend/app/Http/Controllers/API/V1/AudioGenerationController.php`
- Modify: `backend/app/Http/Controllers/API/V1/ImportController.php:47`

- [ ] **Step 1: Add ensureTenantOwns to CampaignsController::update()**

In `backend/app/Http/Controllers/API/V1/CampaignsController.php`, after line 92 (`$campaign = Campaign::findOrFail($id);`), add:

```php
$this->ensureTenantOwns($campaign);
```

- [ ] **Step 2: Add ensureTenantOwns to ContactsController::update() and destroy()**

In `backend/app/Http/Controllers/API/V1/ContactsController.php`:

After line 77 (`$contact = Contact::findOrFail($id);` in update), add:
```php
$this->ensureTenantOwns($contact);
```

After line 90 (`$contact = Contact::findOrFail($id);` in destroy), add:
```php
$this->ensureTenantOwns($contact);
```

- [ ] **Step 3: Add ChecksTenant to ConversationController**

In `backend/app/Http/Controllers/API/V1/ConversationController.php`, after `class ConversationController extends Controller`:

```php
use Concerns\ChecksTenant;
```

Add `$this->ensureTenantOwns($conversation);` after each `Conversation::findOrFail($id)` call in: `show()` (line 41), `sendMessage()` (line 62), `updateStatus()` (line 102).

- [ ] **Step 4: Add ChecksTenant to FunnelController**

In `backend/app/Http/Controllers/API/V1/FunnelController.php`, after `class FunnelController extends Controller`:

```php
use Concerns\ChecksTenant;
```

Add `$this->ensureTenantOwns($funnel);` after each `Funnel::findOrFail($id)` call in: `show()`, `update()`, `destroy()`, `saveCanvas()`, `activate()`, `pause()`, `enroll()`, `duplicate()`, `executions()`, `export()`.

- [ ] **Step 5: Add ChecksTenant to AudioGenerationController**

In `backend/app/Http/Controllers/API/V1/AudioGenerationController.php`, add after class declaration:

```php
use Concerns\ChecksTenant;
```

Add after `$campaign = Campaign::findOrFail($campaignId);` in `generate()`:
```php
$this->ensureTenantOwns($campaign);
```

- [ ] **Step 6: Add ensureTenantOwns to ImportController::status()**

In `backend/app/Http/Controllers/API/V1/ImportController.php`, the Import model needs tenant check. After `$import = Import::findOrFail($id);` in `status()`, add tenant verification:

```php
if ($import->tenant_id !== auth()->user()->tenant_id && auth()->user()->role !== 'superadmin') {
    abort(403, 'Acesso negado');
}
```

Note: Import may not have the tenant scope trait, so we do a manual check.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/CampaignsController.php backend/app/Http/Controllers/API/V1/ContactsController.php backend/app/Http/Controllers/API/V1/ConversationController.php backend/app/Http/Controllers/API/V1/FunnelController.php backend/app/Http/Controllers/API/V1/AudioGenerationController.php backend/app/Http/Controllers/API/V1/ImportController.php
git commit -m "fix(security): add ensureTenantOwns to all controllers — prevent IDOR cross-tenant access"
```

---

## Task 3: Backend Security — Webhook Fixes (C-05, C-11)

**Files:**
- Modify: `backend/app/Http/Controllers/API/V1/InfobipWhatsAppWebhookController.php:25-37`
- Modify: `backend/app/Http/Controllers/API/V1/WebhookController.php:132`

- [ ] **Step 1: Fix ltrim bug in WebhookController**

In `backend/app/Http/Controllers/API/V1/WebhookController.php`, replace line 132:

```php
return hash_equals($secret, ltrim($provided, 'Bearer '));
```

with:

```php
$value = str_starts_with($provided, 'Bearer ') ? substr($provided, 7) : $provided;
return hash_equals($secret, $value);
```

- [ ] **Step 2: Add HMAC/secret validation to InfobipWhatsAppWebhookController**

In `backend/app/Http/Controllers/API/V1/InfobipWhatsAppWebhookController.php`, add constructor and validation:

```php
private \App\Services\SettingsService $settings;

public function __construct(\App\Services\SettingsService $settings)
{
    $this->settings = $settings;
}

public function handle(Request $request): JsonResponse
{
    // Validate webhook secret
    $secret = $this->settings->getGlobal('infobip', 'webhook_secret', '');
    if (!empty($secret)) {
        $provided = $request->header('Authorization') ?? $request->query('secret') ?? '';
        $value = str_starts_with($provided, 'Bearer ') ? substr($provided, 7) : $provided;
        if (!hash_equals($secret, $value)) {
            Log::channel('whatsapp')->warning('infobip.webhook.unauthorized', ['ip' => $request->ip()]);
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }
    } else {
        Log::channel('whatsapp')->warning('infobip.webhook.no_secret_configured');
        return response()->json(['ok' => false, 'message' => 'Webhook secret not configured'], 500);
    }

    $payload = $request->all();
    Log::channel('whatsapp')->debug('infobip.webhook.received', ['message_count' => count($payload['results'] ?? [$payload])]);
    // ... rest of existing handle() logic unchanged
```

- [ ] **Step 3: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/WebhookController.php backend/app/Http/Controllers/API/V1/InfobipWhatsAppWebhookController.php
git commit -m "fix(security): authenticate Infobip WhatsApp webhook + fix ltrim bug in secret validation"
```

---

## Task 4: Backend Quick Fixes (C-07, C-12, C-13, A-11)

**Files:**
- Modify: `backend/app/Console/Commands/DispatchScheduledCampaigns.php:51`
- Modify: `backend/bootstrap/app.php:20-22`
- Modify: `backend/routes/api.php:133-166`
- Modify: `backend/app/Http/Controllers/API/V1/AuthController.php:14-64`
- Create: `backend/app/Http/Controllers/API/V1/ChannelsController.php`
- Create: `backend/app/Http/Controllers/API/V1/PublicPlansController.php`

- [ ] **Step 1: Fix $lock variable overwrite (C-07)**

In `backend/app/Console/Commands/DispatchScheduledCampaigns.php`, replace line 51:

```php
$lock = $credits->lockCampaignIfInsufficient($campaign);
```

with:

```php
$creditCheck = $credits->lockCampaignIfInsufficient($campaign);
```

And update the following references on lines 52-53 from `$lock['locked']`, `$lock['possible_sends']`, `$lock['missing_credits']` to `$creditCheck['locked']`, `$creditCheck['possible_sends']`, `$creditCheck['missing_credits']`.

- [ ] **Step 2: Add exception handler (C-12)**

In `backend/bootstrap/app.php`, replace lines 20-22:

```php
->withExceptions(function (Exceptions $exceptions): void {
    //
})->create();
```

with:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (\App\Exceptions\InvalidCampaignTransitionException $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
    });
    $exceptions->render(function (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erro de validação',
            'errors' => $e->errors(),
        ], 422);
    });
    $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
        return response()->json(['success' => false, 'message' => 'Recurso não encontrado'], 404);
    });
    $exceptions->render(function (\Throwable $e) {
        if (app()->environment('production')) {
            \Illuminate\Support\Facades\Log::error('Unhandled exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    });
})->create();
```

- [ ] **Step 3: Create ChannelsController (C-13)**

Create `backend/app/Http/Controllers/API/V1/ChannelsController.php`:

```php
<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantChannel;
use Illuminate\Http\JsonResponse;

class ChannelsController extends Controller
{
    public function myChannels(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $channels = TenantChannel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('channel');

        $plan = auth()->user()->tenant?->plan;
        $planChannels = $plan ? (json_decode($plan->features, true)['channels'] ?? []) : [];

        $result = [];
        foreach (['sms', 'voice', 'email', 'whatsapp'] as $ch) {
            $existing = $channels->get($ch);
            if ($existing) {
                $result[$ch] = [
                    'status' => $existing->status,
                    'config' => $existing->config ?? [],
                ];
            } else {
                $result[$ch] = [
                    'status' => in_array($ch, $planChannels) ? 'enabled' : 'disabled',
                    'config' => [],
                ];
            }
        }
        return response()->json(['data' => $result]);
    }
}
```

- [ ] **Step 4: Create PublicPlansController (C-13)**

Create `backend/app/Http/Controllers/API/V1/PublicPlansController.php`:

```php
<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PublicPlansController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Plan::orderBy('price_monthly')->get());
    }
}
```

- [ ] **Step 5: Update routes to use controllers**

In `backend/routes/api.php`, replace the closures (lines 133-166):

```php
Route::get('channels', function () { ... });
```
with:
```php
Route::get('channels', [\App\Http\Controllers\API\V1\ChannelsController::class, 'myChannels']);
```

Replace:
```php
Route::get('plans', function () { ... });
```
with:
```php
Route::get('plans', [\App\Http\Controllers\API\V1\PublicPlansController::class, 'index']);
```

- [ ] **Step 6: Wrap register in DB::transaction (A-11)**

In `backend/app/Http/Controllers/API/V1/AuthController.php`, wrap the register method body (lines 22-63) in a transaction:

```php
public function register(Request $request)
{
    $data = $request->validate([
        'name'     => ['required', 'string', 'max:100'],
        'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'string', 'min:8', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()],
    ]);

    return \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
        $freePlan = \App\Models\Plan::where('slug', 'free')->first();
        $tenant = \App\Models\Tenant::create([
            'name'            => $data['name'] . "'s Workspace",
            'slug'            => \Illuminate\Support\Str::slug($data['name']) . '-' . \Illuminate\Support\Str::random(4),
            'plan_id'         => $freePlan?->id,
            'credits_balance' => $freePlan?->credits_included ?? 50,
            'status'          => 'trial',
        ]);

        foreach (['sms' => 'enabled', 'voice' => 'enabled', 'email' => 'disabled', 'whatsapp' => 'disabled'] as $channel => $status) {
            \App\Models\TenantChannel::create([
                'tenant_id' => $tenant->id,
                'channel'   => $channel,
                'status'    => $status,
                'config'    => [],
            ]);
        }

        $user = \App\Models\User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => \Illuminate\Support\Facades\Hash::make($data['password']),
            'tenant_id' => $tenant->id,
            'role'      => 'admin',
        ]);

        $token = $user->createToken('auth')->plainTextToken;
        \App\Models\AuditLog::record('auth.register');

        return ApiResponse::success([
            'user' => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'role'   => $user->role,
                'tenant' => $tenant->only(['id', 'name', 'status', 'credits_balance', 'plan_id']),
            ],
            'token' => $token,
        ], 'Conta criada com sucesso', [], 201);
    });
}
```

- [ ] **Step 7: Verify route:cache works**

Run: `cd backend && php artisan route:cache`
Expected: No "Unable to prepare route for serialization" error.

```bash
php artisan route:clear
```

- [ ] **Step 8: Commit**

```bash
git add backend/app/Console/Commands/DispatchScheduledCampaigns.php backend/bootstrap/app.php backend/routes/api.php backend/app/Http/Controllers/API/V1/ChannelsController.php backend/app/Http/Controllers/API/V1/PublicPlansController.php backend/app/Http/Controllers/API/V1/AuthController.php
git commit -m "fix: $lock overwrite, exception handler, route closures to controllers, atomic register"
```

---

## Task 5: Voice Campaign Backend Validation (C-03)

**Files:**
- Modify: `backend/app/Services/CampaignStateMachine.php:55-59`

- [ ] **Step 1: Require audio_url for voice campaigns**

In `backend/app/Services/CampaignStateMachine.php`, replace lines 55-59:

```php
} elseif (empty($campaign->content) && empty($campaign->audio_url)) {
    $errors[] = 'Conteúdo da campanha é obrigatório.';
}
```

with:

```php
} elseif ($campaign->type === 'voice') {
    if (empty($campaign->audio_url)) {
        $errors[] = 'Áudio é obrigatório para campanhas de voz. Gere o áudio antes de enviar.';
    }
} elseif (empty($campaign->content)) {
    $errors[] = 'Conteúdo da campanha é obrigatório.';
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/app/Services/CampaignStateMachine.php
git commit -m "fix: require audio_url for voice campaigns — text content alone is not sufficient"
```

---

## Task 6: Wizard Validation Fixes (C-02, A-01, A-02, A-03)

**Files:**
- Modify: `frontend/src/pages/campaigns/Create.vue`

- [ ] **Step 1: Fix canAdvance to validate all steps**

In `frontend/src/pages/campaigns/Create.vue`, replace the `canAdvance` computed (lines 180-185):

```typescript
const canAdvance = computed(() => {
  if (currentStep.value === 1) return !!form.value.name && !isLoading.value
  if (currentStep.value === 2 && form.value.type === 'whatsapp') return !!whatsappSettings.value?.template_name
  if (currentStep.value === 3) return step3Valid.value
  return true
})
```

with:

```typescript
const scheduleValid = ref(true)

const canAdvance = computed(() => {
  if (currentStep.value === 1) return !!form.value.name && !isLoading.value
  if (currentStep.value === 2) {
    if (form.value.type === 'whatsapp') return whatsappValid.value
    if (form.value.type === 'voice') return !!form.value.audio_url
    if (form.value.type === 'email') return !!form.value.subject && !!form.value.content
    return !!form.value.content // SMS
  }
  if (currentStep.value === 3) return step3Valid.value
  if (currentStep.value === 4) return scheduleValid.value
  return true
})
```

- [ ] **Step 2: Use payload.valid from Step4Schedule (A-03)**

In the same file, update `onScheduleUpdate` (line 270-273):

```typescript
function onScheduleUpdate(payload: { mode: 'now' | 'schedule'; datetime: string | null; valid: boolean }) {
  scheduleMode.value = payload.mode
  scheduleAt.value = payload.datetime
}
```

to:

```typescript
function onScheduleUpdate(payload: { mode: 'now' | 'schedule'; datetime: string | null; valid: boolean }) {
  scheduleMode.value = payload.mode
  scheduleAt.value = payload.datetime
  scheduleValid.value = payload.valid
}
```

- [ ] **Step 3: Fix contactsTotal — emit from Step3Contacts (A-02)**

In `frontend/src/pages/campaigns/Create.vue`, update the Step3Contacts component to listen for contact count. Add handler:

```typescript
function onContactsCountChange(count: number) {
  contactsTotal.value = count
}
```

Update Step3Contacts template to add the event:
```html
<Step3Contacts
  :channel="form.type"
  v-model="form.contact_list_id"
  @update:valid="step3Valid = $event"
  @update:adhoc-phones="onAdhocPhonesChange"
  @update:source="contactSource = $event"
  @update:contacts-count="onContactsCountChange"
/>
```

Also update `frontend/src/components/campaigns/steps/Step3Contacts.vue` to emit contact count when a list is selected. In the list selection watcher, add:

```typescript
emit('update:contacts-count', selectedList.value?.contact_count ?? 0)
```

And update the emits definition to include:
```typescript
'update:contacts-count': [count: number]
```

- [ ] **Step 4: Add confirmation modal before send (A-01)**

In `frontend/src/pages/campaigns/Create.vue`, add a confirmation state:

```typescript
const showConfirmSend = ref(false)
const confirmAction = ref<'send' | 'schedule'>('send')
```

Replace the `finalSaveAndSend` button (line 112):
```html
<button v-else class="btn btn-danger" @click="finalSaveAndSend" :disabled="isLoading">
```
with:
```html
<button v-else class="btn btn-danger" @click="showConfirmSend = true; confirmAction = 'send'" :disabled="isLoading">
```

Replace the schedule button similarly:
```html
<button v-else-if="scheduleMode === 'schedule' && scheduleAt" class="btn btn-primary" @click="showConfirmSend = true; confirmAction = 'schedule'" :disabled="isLoading">
```

Add a simple confirm modal at the end of the template (before closing `</div>` of row):

```html
<!-- Confirm Send Modal -->
<div v-if="showConfirmSend" class="modal modal-blur fade show d-block" tabindex="-1" style="background:rgba(0,0,0,0.5)">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i :class="confirmAction === 'send' ? 'ti ti-send' : 'ti ti-calendar-event'" class="mb-2" style="font-size:3rem;color:var(--bc-primary,#0064ff)"></i>
        <h3>{{ confirmAction === 'send' ? 'Enviar agora?' : 'Agendar envio?' }}</h3>
        <div class="text-muted">
          {{ form.name }}<br>
          <strong>{{ contactsTotal || adhocPhones.length }}</strong> destinatários ·
          <strong>{{ ((contactsTotal || adhocPhones.length) * creditsPerSend).toLocaleString('pt-BR') }}</strong> créditos
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-link" @click="showConfirmSend = false" :disabled="isLoading">Cancelar</button>
        <button class="btn" :class="confirmAction === 'send' ? 'btn-danger' : 'btn-primary'"
          @click="confirmAction === 'send' ? finalSaveAndSend() : finalSaveAndSchedule()" :disabled="isLoading">
          <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
          {{ confirmAction === 'send' ? 'Confirmar envio' : 'Confirmar agendamento' }}
        </button>
      </div>
    </div>
  </div>
</div>
```

Close the modal after success in `finalSaveAndSend` and `finalSaveAndSchedule` — add `showConfirmSend.value = false` in the finally block.

- [ ] **Step 5: Cancel pending autosave timer in nextStep**

In the `nextStep` function, add at the beginning:

```typescript
const nextStep = async () => {
  clearTimeout(saveTimer) // Cancel any pending autosave
  // ... rest of function
```

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/campaigns/Create.vue frontend/src/components/campaigns/steps/Step3Contacts.vue
git commit -m "fix: wizard validation — block advance without content, validate schedule, confirm modal, contactsTotal"
```

---

## Task 7: Stepper Labels and Accessibility (C-10)

**Files:**
- Modify: `frontend/src/pages/campaigns/Create.vue:13-17`

- [ ] **Step 1: Replace stepper markup**

In `frontend/src/pages/campaigns/Create.vue`, replace lines 13-17:

```html
<ol class="steps steps-counter mb-4">
  <li v-for="n in 5" :key="n" :class="{ 'active': currentStep >= n }">
    <a href="#" class="step-item" @click.prevent="goToStep(n)" :class="{ disabled: currentStepMax < n }"></a>
  </li>
</ol>
```

with:

```html
<ol class="steps steps-counter mb-4">
  <li v-for="(label, i) in stepLabels" :key="i"
    :class="{ 'active': currentStep >= i + 1 }"
    :aria-current="currentStep === i + 1 ? 'step' : undefined">
    <a href="#" class="step-item"
      @click.prevent="goToStep(i + 1)"
      :class="{ disabled: currentStepMax < i + 1 }"
      :aria-label="`Etapa ${i + 1}: ${label}`">
      {{ label }}
    </a>
  </li>
</ol>
```

Add in the `<script setup>`:

```typescript
const stepLabels = ['Nome', 'Conteúdo', 'Contatos', 'Agenda', 'Enviar']
```

- [ ] **Step 2: Add CSS for step labels**

Add a `<style scoped>` block (or append to existing):

```css
.steps .step-item {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/campaigns/Create.vue
git commit -m "fix(a11y): add labels and aria attributes to campaign wizard stepper"
```

---

## Task 8: XSS Fix — DOMPurify in Step2WhatsApp (C-06)

**Files:**
- Modify: `frontend/src/components/campaigns/steps/Step2WhatsApp.vue:118-129`

- [ ] **Step 1: Add DOMPurify to previewBodyHtml**

In `frontend/src/components/campaigns/steps/Step2WhatsApp.vue`, add import:

```typescript
import DOMPurify from 'dompurify'
```

Replace the `previewBodyHtml` function (lines 118-129):

```typescript
function previewBodyHtml(comp: any): string {
  let text = previewBody(comp)
  text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  text = text
    .replace(/\{nome\}/g, '<span class="badge bg-blue-lt">João Silva</span>')
    .replace(/\{telefone\}/g, '<span class="badge bg-green-lt">+5511999999999</span>')
    .replace(/\{email\}/g, '<span class="badge bg-purple-lt">joao@email.com</span>')
    .replace(/\{\{(\d+)\}\}/g, '<span class="badge bg-yellow-lt">Variável $1</span>')
  return DOMPurify.sanitize(text, { ALLOWED_TAGS: ['span'], ALLOWED_ATTR: ['class'] })
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/components/campaigns/steps/Step2WhatsApp.vue
git commit -m "fix(security): sanitize v-html output with DOMPurify in WhatsApp template preview"
```

---

## Task 9: VoiceStudio Sub-Steps Restructure (C-09)

**Files:**
- Modify: `frontend/src/components/campaigns/steps/VoiceStudio.vue`

This is the largest task. The VoiceStudio currently shows everything in one scrollable view. We need to split it into 3 internal tabs/phases:

1. **Roteiro** — AI generation or manual writing
2. **Voz e Áudio** — Voice selection + audio generation
3. **Confirmar** — Audio player + history

- [ ] **Step 1: Read current VoiceStudio.vue completely**

Read the full file to understand all sections and state.

- [ ] **Step 2: Add internal phase state**

Add a `phase` ref and navigation:

```typescript
const phase = ref<1 | 2 | 3>(1)
const canAdvancePhase = computed(() => {
  if (phase.value === 1) return !!(selectedVariation.value || manualScript.value)
  if (phase.value === 2) return !!latestAudioUrl.value
  return true
})
```

- [ ] **Step 3: Wrap existing sections in v-show blocks**

Wrap the briefing/AI section in `v-show="phase === 1"`, the voice/audio section in `v-show="phase === 2"`, and add a confirmation phase 3 that shows the selected audio with a play button.

- [ ] **Step 4: Add mini-stepper navigation at top of VoiceStudio**

```html
<div class="d-flex gap-2 mb-4">
  <button v-for="(lbl, i) in ['1. Roteiro', '2. Voz e Áudio', '3. Confirmar']" :key="i"
    class="btn btn-sm" :class="phase === i + 1 ? 'btn-primary' : 'btn-ghost-secondary'"
    @click="i + 1 <= phase || canAdvancePhase ? phase = (i + 1) as 1|2|3 : null"
    :disabled="i + 1 > phase && !canAdvancePhase">
    {{ lbl }}
  </button>
</div>
```

- [ ] **Step 5: Add phase navigation buttons at bottom of each phase**

Phase 1 bottom:
```html
<button class="btn btn-primary mt-3" @click="phase = 2" :disabled="!canAdvancePhase">
  Próximo: Escolher voz <i class="ti ti-arrow-right ms-1"></i>
</button>
```

Phase 2 bottom:
```html
<button class="btn btn-primary mt-3" @click="phase = 3" :disabled="!latestAudioUrl">
  Próximo: Confirmar áudio <i class="ti ti-arrow-right ms-1"></i>
</button>
```

- [ ] **Step 6: Emit audio_url when confirmed in phase 3**

When user confirms audio in phase 3, emit the save event with the audio URL:
```typescript
function confirmAudio() {
  emit('save', { field: 'audio_url', value: latestAudioUrl.value })
}
```

- [ ] **Step 7: Test the flow**

1. Create voice campaign
2. Phase 1: Generate or write script — verify "Próximo" only enables with content
3. Phase 2: Select voice, generate audio — verify "Próximo" only enables with audio
4. Phase 3: Confirm — verify audio_url is saved
5. Verify parent wizard "Avançar" is disabled until audio_url exists

- [ ] **Step 8: Commit**

```bash
git add frontend/src/components/campaigns/steps/VoiceStudio.vue
git commit -m "refactor: split VoiceStudio into 3 sub-phases — Script, Voice+Audio, Confirm"
```

---

## Task 10: Credit System Atomic Reserve (C-08)

**Files:**
- Modify: `backend/app/Services/Billing/CreditService.php`

- [ ] **Step 1: Implement real reserve with database lock**

Replace the `reserve()` and `release()` stubs with real implementations:

```php
/**
 * Reserve credits atomically — decrements balance inside a transaction.
 * Returns true if reservation succeeded, false if insufficient.
 */
public function reserve(int $tenantId, int $amount, string $referenceType, int $referenceId): bool
{
    return DB::transaction(function () use ($tenantId, $amount, $referenceType, $referenceId) {
        $tenant = \App\Models\Tenant::lockForUpdate()->find($tenantId);
        if (!$tenant || $tenant->credits_balance < $amount) {
            return false;
        }

        $tenant->decrement('credits_balance', $amount);

        \App\Models\CreditTransaction::create([
            'tenant_id'      => $tenantId,
            'amount'         => $amount,
            'type'           => 'reserve',
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'description'    => "Reserva para {$referenceType} #{$referenceId}",
            'balance_after'  => $tenant->credits_balance,
        ]);

        Log::channel('campaign')->info('credits.reserved', compact('tenantId', 'amount', 'referenceType', 'referenceId'));
        return true;
    });
}

/**
 * Release unreserved credits back (e.g., campaign sent fewer than estimated).
 */
public function release(int $tenantId, int $amount, string $referenceType, int $referenceId): void
{
    if ($amount <= 0) return;

    DB::transaction(function () use ($tenantId, $amount, $referenceType, $referenceId) {
        $tenant = \App\Models\Tenant::lockForUpdate()->find($tenantId);
        if (!$tenant) return;

        $tenant->increment('credits_balance', $amount);

        \App\Models\CreditTransaction::create([
            'tenant_id'      => $tenantId,
            'amount'         => $amount,
            'type'           => 'release',
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'description'    => "Liberação de {$referenceType} #{$referenceId}",
            'balance_after'  => $tenant->credits_balance,
        ]);
    });
}
```

- [ ] **Step 2: Update deduct to reject on insufficient balance**

Replace the `deduct()` method's balance calculation:

```php
$newBalance = max(0, (int)$tenant->credits_balance - (int)$amount);
```

with:

```php
if ((int)$tenant->credits_balance < (int)$amount) {
    Log::channel('campaign')->warning('credits.insufficient_at_deduct', [
        'tenant_id' => $tenant->id,
        'balance' => $tenant->credits_balance,
        'amount' => $amount,
    ]);
    return; // Don't deduct if insufficient — should have been reserved
}
$newBalance = (int)$tenant->credits_balance - (int)$amount;
```

- [ ] **Step 3: Add `use Illuminate\Support\Facades\DB;` import if not present**

- [ ] **Step 4: Commit**

```bash
git add backend/app/Services/Billing/CreditService.php
git commit -m "fix: implement atomic credit reservation with lockForUpdate — prevent double-spend"
```

---

## Task 11: Mobile Fallback for Funnel Editor (C-15)

**Files:**
- Modify: `frontend/src/pages/funnels/Editor.vue`

- [ ] **Step 1: Add mobile detection and fallback message**

At the top of the template, before the main editor layout, add:

```html
<!-- Mobile fallback -->
<div class="d-lg-none text-center py-5">
  <div class="mb-3">
    <i class="ti ti-device-desktop" style="font-size:3rem;color:var(--bc-text-muted)"></i>
  </div>
  <h3>Editor disponível em desktop</h3>
  <p class="text-muted">O editor visual de funis requer uma tela maior para funcionar corretamente.<br>Acesse em um computador para editar seus funis.</p>
  <button class="btn btn-primary" @click="$router.push('/funnels')">
    <i class="ti ti-arrow-left me-1"></i> Voltar para funis
  </button>
</div>
```

Wrap the existing editor layout in `<div class="d-none d-lg-flex" style="...existing styles...">`.

- [ ] **Step 2: Commit**

```bash
git add frontend/src/pages/funnels/Editor.vue
git commit -m "fix: add mobile fallback message for funnel editor — requires desktop"
```

---

## Task 12: Minimal Critical Tests (C-14)

**Files:**
- Create: `backend/tests/Feature/CampaignStateMachineTest.php`
- Create: `backend/tests/Feature/TenantIsolationTest.php`
- Create: `backend/tests/Feature/AuthFlowTest.php`

- [ ] **Step 1: Create CampaignStateMachine test**

```php
<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Plan;
use App\Models\TenantChannel;
use App\Services\CampaignStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantAndUser(): array
    {
        $plan = Plan::factory()->create(['slug' => 'free', 'credits_included' => 100]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'credits_balance' => 100]);
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => 'sms', 'status' => 'enabled', 'config' => []]);
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => 'voice', 'status' => 'enabled', 'config' => []]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        return [$tenant, $user];
    }

    public function test_voice_campaign_requires_audio_url(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        $campaign = Campaign::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'voice',
            'content' => 'Some script text',
            'audio_url' => null,
            'contact_list_id' => null,
            'settings' => ['adhoc_phones' => ['+5511999999999']],
            'status' => 'draft',
        ]);

        $machine = new CampaignStateMachine();
        $errors = $machine->assertCanDispatch($campaign);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Áudio é obrigatório', $errors[0]);
    }

    public function test_sms_campaign_allows_text_content(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        $campaign = Campaign::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'sms',
            'content' => 'Hello world',
            'status' => 'draft',
            'settings' => ['adhoc_phones' => ['+5511999999999']],
        ]);

        $machine = new CampaignStateMachine();
        $errors = $machine->assertCanDispatch($campaign);

        $this->assertEmpty($errors);
    }

    public function test_cannot_dispatch_without_contacts(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        $campaign = Campaign::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'sms',
            'content' => 'Hello',
            'contact_list_id' => null,
            'settings' => [],
            'status' => 'draft',
        ]);

        $machine = new CampaignStateMachine();
        $errors = $machine->assertCanDispatch($campaign);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('lista de contatos', $errors[0]);
    }
}
```

- [ ] **Step 2: Create tenant isolation test**

```php
<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_cannot_update_other_tenant_campaign(): void
    {
        $plan = Plan::factory()->create(['slug' => 'test']);
        $tenantA = Tenant::factory()->create(['plan_id' => $plan->id]);
        $tenantB = Tenant::factory()->create(['plan_id' => $plan->id]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'role' => 'admin']);
        $campaignB = Campaign::factory()->create(['tenant_id' => $tenantB->id, 'type' => 'sms', 'status' => 'draft']);

        $response = $this->actingAs($userA)->putJson("/api/v1/campaigns/{$campaignB->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_tenant_cannot_see_other_tenant_campaigns(): void
    {
        $plan = Plan::factory()->create(['slug' => 'test2']);
        $tenantA = Tenant::factory()->create(['plan_id' => $plan->id]);
        $tenantB = Tenant::factory()->create(['plan_id' => $plan->id]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'role' => 'admin']);
        Campaign::factory()->create(['tenant_id' => $tenantB->id, 'type' => 'sms', 'status' => 'draft', 'name' => 'Secret']);

        $response = $this->actingAs($userA)->getJson('/api/v1/campaigns');

        $response->assertStatus(200);
        $response->assertJsonMissing(['name' => 'Secret']);
    }
}
```

- [ ] **Step 3: Create auth flow test**

```php
<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_tenant_and_returns_token(): void
    {
        Plan::factory()->create(['slug' => 'free', 'credits_included' => 50]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'tenant'], 'token']]);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.user.tenant.id'));
    }

    public function test_login_returns_token(): void
    {
        Plan::factory()->create(['slug' => 'free', 'credits_included' => 50]);

        // Register first
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test',
            'email' => 'login@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ]);

        // Login
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'Password1',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['user', 'token']]);
    }
}
```

- [ ] **Step 4: Ensure factories exist**

Check that `CampaignFactory`, `TenantFactory`, `PlanFactory`, and `UserFactory` exist. If not, create minimal factories. Run: `php artisan test --filter=CampaignStateMachineTest --filter=TenantIsolationTest --filter=AuthFlowTest`

- [ ] **Step 5: Run tests**

```bash
cd backend && php artisan test
```

Expected: All new tests pass. Existing tests should not break.

- [ ] **Step 6: Commit**

```bash
git add backend/tests/Feature/CampaignStateMachineTest.php backend/tests/Feature/TenantIsolationTest.php backend/tests/Feature/AuthFlowTest.php
git commit -m "test: add critical tests — campaign state machine, tenant isolation, auth flow"
```

---

## Summary: Task Dependencies

```
Independent (can run in parallel):
├── Task 1: Registration fix (frontend)
├── Task 2: Tenant security (backend)
├── Task 3: Webhook fixes (backend)
├── Task 4: Quick backend fixes (backend)
├── Task 5: Voice validation (backend)
├── Task 7: Stepper labels (frontend)
├── Task 8: XSS fix (frontend)
├── Task 9: VoiceStudio restructure (frontend)
├── Task 10: Credit system (backend)
├── Task 11: Mobile fallback (frontend)
└── Task 12: Tests (backend, depends on Tasks 2+5 being done)

Sequential:
└── Task 6: Wizard validation (frontend, should be done after Tasks 5+9 for full coverage)
```
