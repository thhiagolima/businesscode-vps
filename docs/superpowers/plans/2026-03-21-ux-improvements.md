# UX Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve 5 core UX flows: wizard step 3, send confirmation, campaign list, CSV import, and contacts page.

**Architecture:** Backend-first — add missing API features (filters, batch endpoint, per_page), then rewrite frontend components one at a time. Each task produces a working commit.

**Tech Stack:** Laravel 12 (backend), Vue 3 + Tabler UI + Pinia (frontend), Axios (HTTP)

**Spec:** `docs/superpowers/specs/2026-03-21-ux-improvements-design.md`

---

## File Structure

### Backend (modify)
- `backend/app/Http/Controllers/API/V1/CampaignsController.php` — add filters to index()
- `backend/app/Http/Controllers/API/V1/ContactsController.php` — add per_page, batch()
- `backend/app/Http/Controllers/API/V1/ImportController.php` — add max:10240 validation
- `backend/routes/api.php` — add batch route

### Frontend (create)
- `frontend/src/components/campaigns/steps/Step3Contacts.vue` — dropdown + preview
- `frontend/src/components/campaigns/ConfirmSendModal.vue` — send confirmation modal
- `frontend/src/components/contacts/ContactsSidebar.vue` — list sidebar
- `frontend/src/components/contacts/BatchActionsBar.vue` — batch actions bar

### Frontend (modify)
- `frontend/src/pages/campaigns/Create.vue` — wire Step3Contacts
- `frontend/src/pages/campaigns/Index.vue` — rewrite with filters/pagination
- `frontend/src/pages/campaigns/Detail.vue` — wire ConfirmSendModal
- `frontend/src/pages/contacts/Index.vue` — rewrite with sidebar/batch
- `frontend/src/components/contacts/ImportCsvModal.vue` — rewrite 3-step wizard

---

## Task 1: Backend — Campaign list filters + per_page on contacts

**Files:**
- Modify: `backend/app/Http/Controllers/API/V1/CampaignsController.php:15-19`
- Modify: `backend/app/Http/Controllers/API/V1/ContactsController.php:12-36`

- [ ] **Step 1: Update CampaignsController::index() with filters**

```php
public function index(Request $request)
{
    $validated = $request->validate([
        'search' => ['nullable', 'string', 'max:255'],
        'type'   => ['nullable', 'in:sms,voice,email'],
        'status' => ['nullable', 'in:draft,scheduled,processing,running,completed,failed'],
    ]);

    $q = Campaign::query()->with('contactList:id,name,contact_count');

    if (!empty($validated['search'])) {
        $q->where('name', 'like', '%'.$validated['search'].'%');
    }
    if (!empty($validated['type'])) {
        $q->where('type', $validated['type']);
    }
    if (!empty($validated['status'])) {
        $q->where('status', $validated['status']);
    }

    $paginator = $q->orderByDesc('id')->paginate(20);
    return ApiResponse::paginated($paginator, 'OK');
}
```

- [ ] **Step 2: Add per_page param to ContactsController::index()**

Replace line 34 in `ContactsController.php`:
```php
// old: $items = $q->orderBy('id', 'desc')->paginate(20);
$perPage = min((int) $request->input('per_page', 20), 50);
$items = $q->orderBy('id', 'desc')->paginate($perPage);
```

Add `'per_page'` to the validate array:
```php
$request->validate([
    'contact_list_id' => ['nullable', 'integer'],
    'status' => ['nullable', 'in:active,blocked,invalid'],
    'search' => ['nullable', 'string', 'max:255'],
    'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
]);
```

- [ ] **Step 3: Verify with artisan**

Run: `cd backend && php artisan route:clear && php artisan route:list --path=v1/campaigns`
Expected: Routes listed without errors

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/CampaignsController.php backend/app/Http/Controllers/API/V1/ContactsController.php
git commit -m "feat: add filters to campaign list + per_page to contacts"
```

---

## Task 2: Backend — Batch contacts endpoint + import file size

**Files:**
- Modify: `backend/app/Http/Controllers/API/V1/ContactsController.php`
- Modify: `backend/app/Http/Controllers/API/V1/ImportController.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Add batch() method to ContactsController**

Add at end of class, before closing `}`:

```php
public function batch(Request $request)
{
    $validated = $request->validate([
        'action'         => ['required', 'in:move,status,delete'],
        'contact_ids'    => ['required', 'array', 'max:500'],
        'contact_ids.*'  => ['integer'],
        'target_list_id' => ['required_if:action,move', 'nullable', 'integer', 'exists:contact_lists,id'],
        'target_status'  => ['required_if:action,status', 'nullable', 'in:active,blocked,invalid'],
    ]);

    $query = Contact::whereIn('id', $validated['contact_ids']);

    switch ($validated['action']) {
        case 'move':
            $oldListIds = (clone $query)->pluck('contact_list_id')->unique();
            $query->update(['contact_list_id' => $validated['target_list_id']]);
            foreach ($oldListIds as $listId) {
                \App\Models\ContactList::where('id', $listId)->update([
                    'contact_count' => Contact::where('contact_list_id', $listId)->count()
                ]);
            }
            \App\Models\ContactList::where('id', $validated['target_list_id'])->update([
                'contact_count' => Contact::where('contact_list_id', $validated['target_list_id'])->count()
            ]);
            break;
        case 'status':
            $query->update(['status' => $validated['target_status']]);
            break;
        case 'delete':
            $listIds = (clone $query)->pluck('contact_list_id')->unique();
            $query->delete();
            foreach ($listIds as $listId) {
                \App\Models\ContactList::where('id', $listId)->update([
                    'contact_count' => Contact::where('contact_list_id', $listId)->count()
                ]);
            }
            break;
    }

    return ApiResponse::success([], 'Operação concluída');
}
```

- [ ] **Step 2: Add batch route in api.php**

**IMPORTANT:** Place this route BEFORE `Route::apiResource('contacts', ...)` to avoid Laravel routing `contacts/batch` as `contacts/{contact}` where `{contact}` = "batch".

```php
Route::post('contacts/batch', [ContactsController::class, 'batch']);
```

- [ ] **Step 3: Add max file size to ImportController**

In `ImportController::upload()`, change the file validation:
```php
'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
```

- [ ] **Step 4: Verify syntax and routes**

Run: `php -l app/Http/Controllers/API/V1/ContactsController.php && php artisan route:list --path=v1/contacts`
Expected: No syntax errors, `POST api/v1/contacts/batch` visible

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/ContactsController.php backend/app/Http/Controllers/API/V1/ImportController.php backend/routes/api.php
git commit -m "feat: add batch contacts endpoint + import file size limit"
```

---

## Task 3: Frontend — ConfirmSendModal component

**Files:**
- Create: `frontend/src/components/campaigns/ConfirmSendModal.vue`

- [ ] **Step 1: Create ConfirmSendModal.vue**

```vue
<template>
  <div class="modal modal-blur fade" :class="{ show: visible }" :style="{ display: visible ? 'block' : 'none' }" tabindex="-1">
    <div class="modal-dialog modal-md modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Confirmar disparo</h5>
          <button type="button" class="btn-close" @click="$emit('cancelled')"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning">
            <i class="ti ti-alert-triangle me-2"></i>
            Esta ação não pode ser desfeita. As mensagens serão enviadas imediatamente.
          </div>
          <table class="table table-borderless table-sm mb-0">
            <tbody>
              <tr>
                <td class="text-muted" style="width:140px">Campanha</td>
                <td class="fw-bold">{{ campaign.name }}</td>
              </tr>
              <tr>
                <td class="text-muted">Canal</td>
                <td><span :class="['badge', channelBadge]">{{ channelLabel }}</span></td>
              </tr>
              <tr>
                <td class="text-muted">Lista</td>
                <td class="fw-bold">{{ campaign.contact_list?.name ?? '—' }}</td>
              </tr>
              <tr>
                <td class="text-muted">Destinatários</td>
                <td class="fw-bold">{{ campaign.estimated_contacts?.toLocaleString('pt-BR') ?? 0 }}</td>
              </tr>
              <tr>
                <td class="text-muted">Custo estimado</td>
                <td class="fw-bold text-primary">{{ estimatedCost.toLocaleString('pt-BR') }} créditos</td>
              </tr>
              <tr>
                <td class="text-muted">Saldo após envio</td>
                <td :class="['fw-bold', remainingBalance >= 0 ? 'text-success' : 'text-danger']">
                  {{ remainingBalance.toLocaleString('pt-BR') }} créditos
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="contentPreview" class="bg-light rounded p-2 mt-3" style="font-size:0.8rem;max-height:80px;overflow:hidden">
            <strong>Preview:</strong> {{ contentPreview }}
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-link link-secondary" @click="$emit('cancelled')">Cancelar</button>
          <button class="btn btn-danger" @click="$emit('confirmed')" :disabled="isSending">
            <span v-if="isSending" class="spinner-border spinner-border-sm me-2"></span>
            <i v-else class="ti ti-send me-1"></i>
            Confirmar Disparo
          </button>
        </div>
      </div>
    </div>
  </div>
  <div v-if="visible" class="modal-backdrop fade show"></div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  visible: boolean
  campaign: {
    id: number
    name: string
    type: 'sms' | 'voice' | 'email'
    content?: string
    subject?: string
    estimated_contacts: number
    contact_list?: { name: string }
  }
  creditsBalance: number
  isSending?: boolean
}>()

defineEmits<{
  confirmed: []
  cancelled: []
}>()

const unitRate: Record<string, number> = { sms: 1, voice: 5, email: 2 }

const channelLabel = computed(() => ({ sms: 'SMS', voice: 'Voz', email: 'Email' }[props.campaign.type] ?? props.campaign.type))
const channelBadge = computed(() => ({ sms: 'bg-green', voice: 'bg-pink', email: 'bg-blue' }[props.campaign.type] ?? 'bg-secondary'))
const estimatedCost = computed(() => (props.campaign.estimated_contacts ?? 0) * (unitRate[props.campaign.type] ?? 1))
const remainingBalance = computed(() => props.creditsBalance - estimatedCost.value)
const contentPreview = computed(() => {
  const text = props.campaign.content ?? props.campaign.subject ?? ''
  return text.length > 200 ? text.slice(0, 200) + '...' : text
})
</script>
```

- [ ] **Step 2: Verify build**

Run: `cd frontend && npx vue-tsc --noEmit 2>&1 | head -20 || npm run build 2>&1 | tail -5`
Expected: No errors related to ConfirmSendModal

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/campaigns/ConfirmSendModal.vue
git commit -m "feat: add ConfirmSendModal component"
```

---

## Task 4: Frontend — Wire ConfirmSendModal into Detail.vue

**Note:** Index.vue will be fully rewritten in Task 5 (which includes the modal). This task only wires the modal into Detail.vue.

**Files:**
- Modify: `frontend/src/pages/campaigns/Detail.vue`

- [ ] **Step 1: Wire modal into Detail.vue**

Add imports:
```ts
import ConfirmSendModal from '@/components/campaigns/ConfirmSendModal.vue'
import { useAuthStore } from '@/stores/auth'
const auth = useAuthStore()
```

Add state (these refs already exist in Detail.vue's setup: `ref`, `computed`, `useApi`, `useRoute`, `useToast`):
```ts
const confirmOpen = ref(false)
const isSending = ref(false)
```

Add send handler:
```ts
async function doSendNow() {
  if (!campaign.value) return
  isSending.value = true
  try {
    const { post } = useApi()
    await post(`/campaigns/${campaign.value.id}/send-now`)
    toast.success('Disparo iniciado')
    confirmOpen.value = false
    await loadCampaign()
    await loadKpis()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao disparar')
  } finally {
    isSending.value = false
  }
}
```

In template header, add "Enviar agora" button for draft campaigns:
```html
<button v-if="campaign?.status==='draft'" class="btn btn-primary" @click="confirmOpen = true">
  <i class="ti ti-send me-1"></i>Enviar agora
</button>
```

Add modal before `</template>`:
```html
<ConfirmSendModal
  v-if="campaign"
  :visible="confirmOpen"
  :campaign="{ ...campaign, estimated_contacts: kpis[0]?.value ?? 0 }"
  :credits-balance="auth.user?.tenant?.credits_balance ?? 0"
  :is-sending="isSending"
  @confirmed="doSendNow"
  @cancelled="confirmOpen = false"
/>
```

- [ ] **Step 2: Build and verify**

Run: `npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/campaigns/Detail.vue
git commit -m "feat: wire send confirmation modal into campaign detail page"
```

---

## Task 5: Frontend — Campaign list rewrite with filters + pagination + confirm modal

**Files:**
- Modify: `frontend/src/pages/campaigns/Index.vue` — full rewrite (includes ConfirmSendModal wiring)

- [ ] **Step 1: Rewrite Index.vue**

Replace entire file. Key requirements:

**TypeScript type** (expanded to include fields needed by ConfirmSendModal):
```ts
type Campaign = {
  id: number; name: string; type: 'sms'|'voice'|'email'; status: string;
  content?: string; subject?: string; estimated_contacts?: number;
  created_at?: string; sent_count?: number; failed_count?: number;
  contact_list?: { id: number; name: string; contact_count: number }
}
```

**Imports:**
```ts
import ConfirmSendModal from '@/components/campaigns/ConfirmSendModal.vue'
import { useAuthStore } from '@/stores/auth'
```

**Filter bar:** search input (debounce 300ms), type select, status select

**Status mapping (DB → PT label):**
```ts
const statusMap: Record<string, { label: string; class: string }> = {
  draft:      { label: 'Rascunho',    class: 'bg-warning text-dark' },
  scheduled:  { label: 'Agendada',    class: 'bg-info' },
  processing: { label: 'Processando', class: 'bg-azure' },
  running:    { label: 'Em execução', class: 'bg-azure' },
  completed:  { label: 'Concluída',   class: 'bg-success' },
  failed:     { label: 'Falhou',      class: 'bg-danger' },
}
```

**Table columns:** Nome, Canal (badge), Status (badge), Contatos, Taxa entrega (%), Criada em, Ações (⋮)

**Dropdown actions per row:**
- Ver detalhes → `router.push('/campaigns/' + id)`
- Editar → `router.push('/campaigns/' + id)` (same as detail for now)
- Duplicar → `post('/campaigns', { name: c.name + ' (cópia)', type: c.type })` then reload
- Enviar agora → opens ConfirmSendModal (only for draft/scheduled)
- Deletar → `confirm()` + `del('/campaigns/' + id)` then reload

**ConfirmSendModal wiring:**
```ts
const confirmTarget = ref<Campaign | null>(null)
const isSending = ref(false)
// openConfirm(c), doSendNow() — same pattern as Task 4
```

**Pagination:**
```ts
const filters = ref({ search: '', type: '', status: '' })
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 })

async function load(page = 1) {
  const res = await get<any>('/campaigns', {
    page,
    search: filters.value.search || undefined,
    type: filters.value.type || undefined,
    status: filters.value.status || undefined,
  })
  items.value = res?.data ?? []
  pagination.value = res?.meta ?? pagination.value
}
```

- Delivery rate: `sent_count > 0 ? round(sent_count / estimated_contacts * 100) : '—'`
- Rate color: green ≥80, yellow ≥50, red <50
- Row click → detail, hover state on rows
- Loading skeleton, empty state

- [ ] **Step 2: Build and verify**

Run: `npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/campaigns/Index.vue
git commit -m "feat: rewrite campaign list with filters, pagination, and actions"
```

---

## Task 6: Frontend — Step3Contacts component

**Files:**
- Create: `frontend/src/components/campaigns/steps/Step3Contacts.vue`
- Modify: `frontend/src/pages/campaigns/Create.vue:59-65`

- [ ] **Step 1: Create Step3Contacts.vue**

Component with:
- `onMounted`: fetch `GET /v1/contact-lists` → populate select options
- Select with search filter (v-model on selected list ID)
- On select change: fetch `GET /v1/contacts?contact_list_id={id}&per_page=5` → show preview card
- Preview card: list name, contact_count, first 5 contacts (name + masked phone)
- Credit estimation: `contact_count × unitRate[channel]`
- "Criar nova lista" button → inline form (name input + POST `/v1/contact-lists`)
- Empty state if no lists exist
- Loading/error/empty UX states per spec

Props: `channel: 'sms' | 'voice' | 'email'`, `modelValue: number | null`
Emits: `update:modelValue`, `update:valid` (boolean — has list with ≥1 contact)

- [ ] **Step 2: Wire into Create.vue**

Replace step 3 section (lines 59-65):
```html
<div v-show="currentStep === 3">
  <Step3Contacts
    :channel="form.type"
    v-model="form.contact_list_id"
    @update:valid="step3Valid = $event"
  />
</div>
```

Add import and ref:
```ts
import Step3Contacts from '@/components/campaigns/steps/Step3Contacts.vue'
const step3Valid = ref(false)
```

Disable "Avançar" on step 3 when `!step3Valid`:
```ts
// In the advance button, add condition for step 3:
:disabled="(currentStep === 1 && (!form.name || isLoading)) || (currentStep === 3 && !step3Valid)"
```

- [ ] **Step 3: Build and verify**

Run: `npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/campaigns/steps/Step3Contacts.vue frontend/src/pages/campaigns/Create.vue
git commit -m "feat: add Step3Contacts with dropdown, preview, and credit estimation"
```

---

## Task 7a: Frontend — ImportCsvModal Step 1 (Upload)

**Files:**
- Modify: `frontend/src/components/contacts/ImportCsvModal.vue` — begin rewrite

- [ ] **Step 1: Scaffold 3-step wizard structure**

Replace entire file. Create wizard with `currentStep` ref (1/2/3), step indicator at top, and step 1 content:

- Drag-drop zone with `@dragover.prevent`, `@drop.prevent`, `@click` triggers hidden `<input type="file">`
- Accept `.csv,.txt`, validate max 10MB client-side (`file.size > 10 * 1024 * 1024`)
- "Download template" link: generates CSV blob `phone;name;email\n+5511999999999;João Silva;joao@email.com`
- Select list destination from `GET /v1/contact-lists` (fetched on mount)
- "Criar nova lista" option inline
- "Próximo →" button disabled until file selected

- [ ] **Step 2: Build and verify**

Run: `npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/contacts/ImportCsvModal.vue
git commit -m "feat: ImportCsvModal step 1 - drag-drop upload + list selection"
```

---

## Task 7b: Frontend — ImportCsvModal Step 2 (Preview + Mapping)

**Files:**
- Modify: `frontend/src/components/contacts/ImportCsvModal.vue` — add step 2

- [ ] **Step 1: Add CSV parsing and preview**

When advancing from step 1 to step 2:
- Read file with `FileReader.readAsText()`
- Detect delimiter: count `;` vs `,` in first line
- Parse first 6 lines (1 header + 5 data rows)
- Display table: header row as `<select>` per column with options Telefone/Nome/Email/Ignorar
- Auto-detect mapping: column name contains `phone|telefone|celular` → Telefone, `name|nome` → Nome, `email|e-mail` → Email
- Show counters below table: total rows (file line count - 1), valid (basic phone regex `/^\+?\d{10,15}$/`), invalid
- "← Voltar" and "Importar N contatos →" buttons

- [ ] **Step 2: Build and verify**

Run: `npm run build 2>&1 | tail -5`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/contacts/ImportCsvModal.vue
git commit -m "feat: ImportCsvModal step 2 - CSV preview with column mapping"
```

---

## Task 7c: Frontend — ImportCsvModal Step 3 (Importing + Progress)

**Files:**
- Modify: `frontend/src/components/contacts/ImportCsvModal.vue` — add step 3

- [ ] **Step 1: Add upload and polling**

When advancing from step 2 to step 3:
- POST `/v1/contacts/import` with FormData (file + mapping object + contact_list_id)
- Start polling `GET /v1/contacts/import/{id}` with exponential backoff: 2s → 4s → 8s → max 10s
- Show progress bar: `processed_rows / total_rows * 100`
- When `status === 'completed'`: show summary (imported count, errors if any)
- When `status === 'failed'`: show error message
- "Concluir" button emits `@submitted` and resets modal state

- [ ] **Step 2: Build and verify**

Run: `npm run build 2>&1 | tail -5`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/contacts/ImportCsvModal.vue
git commit -m "feat: ImportCsvModal step 3 - upload progress with polling"
```

---

## Task 8: Frontend — ContactsSidebar + BatchActionsBar components

**Files:**
- Create: `frontend/src/components/contacts/ContactsSidebar.vue`
- Create: `frontend/src/components/contacts/BatchActionsBar.vue`

- [ ] **Step 1: Create ContactsSidebar.vue**

Props: `lists: Array<{id, name, contact_count}>`, `selectedId: number | null`
Emits: `select(id | null)`, `created`, `renamed(id, name)`, `deleted(id)`

- "Todas" item at top (sum of all contact_count)
- Each list item: name + count, click to select, highlight when active
- Rename: pencil icon → inline input, Enter to save (`PUT /v1/contact-lists/{id}`)
- Delete: trash icon → `confirm()` → `DELETE /v1/contact-lists/{id}`
- "+ Nova lista" button at bottom → inline input, Enter to create (`POST /v1/contact-lists`)

- [ ] **Step 2: Create BatchActionsBar.vue**

Props: `selectedCount: number`, `lists: Array<{id, name}>`
Emits: `move(listId)`, `status(status)`, `delete`, `clear`

- Blue bar with: "N selecionados", Move dropdown, Status dropdown, Delete button, ✕ clear
- Move dropdown: list all available lists
- Status dropdown: Ativo, Bloqueado, Inválido
- Delete: red button, no inline confirm (parent handles confirmation)

- [ ] **Step 3: Build and verify**

Run: `npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/contacts/ContactsSidebar.vue frontend/src/components/contacts/BatchActionsBar.vue
git commit -m "feat: add ContactsSidebar and BatchActionsBar components"
```

---

## Task 9: Frontend — Contacts page rewrite

**Files:**
- Modify: `frontend/src/pages/contacts/Index.vue` — full rewrite

- [ ] **Step 1: Rewrite Index.vue**

Layout: sidebar (200px) + main content area using `d-flex`.

**Sidebar:** `<ContactsSidebar>` with lists from `GET /v1/contact-lists`

**Main area:**
- Header: "Contatos" title + "Importar CSV" button
- Filter bar: search input (debounce 300ms) + status select
- Table: checkbox + Nome + Telefone + Email + Status
  - Select-all checkbox in header
  - Row checkboxes for batch selection
- `<BatchActionsBar>` shown when `selectedIds.length > 0`
- Pagination footer

**Data flow:**
```ts
const selectedListId = ref<number | null>(null)
const selectedIds = ref<Set<number>>(new Set())
const filters = ref({ search: '', status: '' })

async function fetchContacts(page = 1) {
  const res = await get('/contacts', {
    page,
    contact_list_id: selectedListId.value || undefined,
    search: filters.value.search || undefined,
    status: filters.value.status || undefined,
  })
  // ...
}
```

**Batch action handlers:**
```ts
async function onBatchMove(listId: number) { await post('/contacts/batch', { action: 'move', contact_ids: [...selectedIds.value], target_list_id: listId }); selectedIds.value.clear(); reload() }
async function onBatchStatus(status: string) { await post('/contacts/batch', { action: 'status', contact_ids: [...selectedIds.value], target_status: status }); selectedIds.value.clear(); reload() }
async function onBatchDelete() { if (!confirm('Deletar contatos selecionados?')) return; await post('/contacts/batch', { action: 'delete', contact_ids: [...selectedIds.value] }); selectedIds.value.clear(); reload() }
```

- [ ] **Step 2: Build and verify**

Run: `npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/contacts/Index.vue
git commit -m "feat: rewrite contacts page with sidebar, batch actions, and pagination"
```

---

## Task 10: Final verification

- [ ] **Step 1: PHP syntax check all backend files**

Run: `cd backend && php -l app/Http/Controllers/API/V1/CampaignsController.php && php -l app/Http/Controllers/API/V1/ContactsController.php && php -l app/Http/Controllers/API/V1/ImportController.php`
Expected: `No syntax errors detected` for all

- [ ] **Step 2: Route cache test**

Run: `php artisan route:list --path=v1 2>&1 | wc -l`
Expected: ~70 routes, no errors

- [ ] **Step 3: Frontend build**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs` with no errors

- [ ] **Step 4: Manual smoke test checklist**

Open `http://localhost:5173` and verify:
1. Campaign list: filters work (search, type, status), pagination buttons, row click → detail
2. Campaign list: "Enviar agora" → confirmation modal appears with summary
3. Campaign create: step 3 shows dropdown with lists, preview card renders
4. Contacts: sidebar shows lists, click filters table
5. Contacts: checkbox selection + batch bar appears
6. Import CSV: drag-drop → preview with mapping → progress bar

- [ ] **Step 5: Final commit if any fixes needed**

```bash
git add -A && git commit -m "fix: address issues found in smoke testing"
```
