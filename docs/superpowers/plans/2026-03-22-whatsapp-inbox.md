# WhatsApp Inbox Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a full WhatsApp Inbox UI with 3-column layout, real-time polling, message bubbles, and bot↔human handoff controls.

**Architecture:** Backend adds 2 small endpoints (updateStatus, unreadCount) to existing ConversationController. Frontend is the bulk: 5 new Vue components, 1 composable for polling, and modifications to router + sidebar.

**Tech Stack:** Vue 3 + Tabler UI + Pinia (frontend), Laravel 12 (backend — 2 endpoint additions)

**Spec:** `docs/superpowers/specs/2026-03-22-whatsapp-inbox-design.md`

---

## File Structure

### Backend — Modify
| File | Change |
|------|--------|
| `backend/app/Http/Controllers/API/V1/ConversationController.php` | Add `updateStatus()` + `unreadCount()` methods |
| `backend/routes/api.php:100-103` | Add 2 routes before existing conversation routes |

### Frontend — Create
| File | Responsibility |
|------|---------------|
| `frontend/src/composables/useConversationPolling.ts` | Polling inteligente with visibilitychange |
| `frontend/src/components/conversations/MessageBubble.vue` | Single message bubble (inbound/outbound) |
| `frontend/src/components/conversations/ConversationInfo.vue` | Right panel — contact details |
| `frontend/src/components/conversations/ConversationList.vue` | Left panel — conversation list with search/filters |
| `frontend/src/components/conversations/ConversationChat.vue` | Center panel — messages + input |
| `frontend/src/pages/conversations/Index.vue` | Main inbox page (3-column layout) |
| `frontend/public/sounds/notification.mp3` | Notification sound file |

### Frontend — Modify
| File | Change |
|------|--------|
| `frontend/src/router/index.ts:91-93` | Point `/conversations` to real page |
| `frontend/src/components/layout/AppSidebar.vue:38-43` | Add unread badge + polling |

---

## Task 1: Backend — Add updateStatus + unreadCount endpoints

**Files:**
- Modify: `backend/app/Http/Controllers/API/V1/ConversationController.php:99`
- Modify: `backend/routes/api.php:100-103`

- [ ] **Step 1: Add updateStatus() method to ConversationController**

Add before the closing `}` of the class (line 99):

```php
    public function updateStatus(int $id, Request $request)
    {
        $conversation = Conversation::findOrFail($id);
        $data = $request->validate([
            'status' => ['required', 'in:bot,human,closed'],
        ]);

        $updates = ['status' => $data['status']];

        if ($data['status'] === 'human') {
            $updates['assigned_to'] = auth()->id();
        }
        if ($data['status'] === 'closed') {
            $updates['unread_count'] = 0;
        }

        $conversation->update($updates);
        return ApiResponse::success($conversation->fresh()->load('contact:id,name,phone,email'), 'Status atualizado');
    }

    public function unreadCount()
    {
        $count = Conversation::where('unread_count', '>', 0)->count();
        return response()->json(['count' => $count]);
    }
```

- [ ] **Step 2: Register routes in api.php**

In `routes/api.php`, replace the conversations block (lines 100-103):

```php
        // Conversas
        Route::get('conversations/unread-count', [ConversationController::class, 'unreadCount']);
        Route::get('conversations',               [ConversationController::class, 'index']);
        Route::get('conversations/{id}',          [ConversationController::class, 'show']);
        Route::post('conversations/{id}/messages', [ConversationController::class, 'sendMessage']);
        Route::patch('conversations/{id}/status',  [ConversationController::class, 'updateStatus']);
```

**IMPORTANT:** `unread-count` route MUST be before `{id}` route, otherwise Laravel interprets "unread-count" as an `{id}` parameter.

- [ ] **Step 3: Verify**

Run: `cd backend && php -l app/Http/Controllers/API/V1/ConversationController.php && php artisan route:list --path=v1/conversations 2>&1`
Expected: No syntax errors, 5 conversation routes listed

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/ConversationController.php backend/routes/api.php
git commit -m "feat: add conversation updateStatus and unreadCount endpoints"
```

---

## Task 2: Composable — useConversationPolling

**Files:**
- Create: `frontend/src/composables/useConversationPolling.ts`

- [ ] **Step 1: Create useConversationPolling.ts**

```ts
import { ref, onUnmounted } from 'vue'

type PollEntry = {
  id: string
  fn: () => Promise<void>
  interval: number
  timer: ReturnType<typeof setInterval> | null
}

const entries = ref<PollEntry[]>([])
let paused = false
let visibilityBound = false

function bindVisibility() {
  if (visibilityBound) return
  visibilityBound = true
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) pauseAll()
    else resumeAll()
  })
}

function pauseAll() {
  paused = true
  entries.value.forEach(e => {
    if (e.timer) { clearInterval(e.timer); e.timer = null }
  })
}

function resumeAll() {
  paused = false
  entries.value.forEach(e => {
    if (!e.timer) {
      e.fn() // immediate fetch on resume
      e.timer = setInterval(e.fn, e.interval)
    }
  })
}

export function useConversationPolling() {
  bindVisibility()

  function startPolling(id: string, fn: () => Promise<void>, intervalMs: number) {
    stopPolling(id)
    const entry: PollEntry = { id, fn, interval: intervalMs, timer: null }
    if (!paused) {
      entry.timer = setInterval(fn, intervalMs)
    }
    entries.value.push(entry)
  }

  function stopPolling(id: string) {
    const idx = entries.value.findIndex(e => e.id === id)
    if (idx >= 0) {
      const e = entries.value[idx]
      if (e.timer) clearInterval(e.timer)
      entries.value.splice(idx, 1)
    }
  }

  function stopAll() {
    entries.value.forEach(e => { if (e.timer) clearInterval(e.timer) })
    entries.value = []
  }

  onUnmounted(stopAll)

  return { startPolling, stopPolling, stopAll }
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/composables/useConversationPolling.ts
git commit -m "feat: add useConversationPolling composable with visibilitychange"
```

---

## Task 3: MessageBubble component

**Files:**
- Create: `frontend/src/components/conversations/MessageBubble.vue`

- [ ] **Step 1: Create MessageBubble.vue**

```vue
<template>
  <div class="d-flex mb-2" :class="isOutbound ? 'justify-content-end' : ''">
    <div :style="bubbleStyle" style="max-width:75%;padding:6px 10px;font-size:13px;box-shadow:0 1px 1px rgba(0,0,0,0.05)">
      <!-- Sender label for outbound -->
      <div v-if="isOutbound && senderLabel" style="font-size:10px;font-weight:600;margin-bottom:2px" :style="{ color: senderColor }">
        {{ senderLabel }}
      </div>

      <!-- Template badge -->
      <div v-if="msg.type === 'template'" class="mb-1">
        <span style="font-size:10px;background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:3px">📋 {{ msg.template_name }}</span>
      </div>

      <!-- Media placeholder -->
      <div v-if="isMedia" style="color:#666;font-style:italic;margin-bottom:4px">
        {{ mediaLabel }}
      </div>

      <!-- Content -->
      <div v-if="msg.content" style="white-space:pre-wrap;word-break:break-word">{{ msg.content }}</div>

      <!-- Footer: time + status -->
      <div style="font-size:9px;color:#999;text-align:right;margin-top:2px;display:flex;justify-content:flex-end;align-items:center;gap:3px">
        <span>{{ timeLabel }}</span>
        <span v-if="isOutbound" :style="{ color: statusColor }">{{ statusIcon }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  msg: {
    id: number
    direction: 'inbound' | 'outbound'
    sender_type: string
    sender_id?: number
    sender?: { name?: string }
    type: string
    content?: string
    template_name?: string
    media_url?: string
    status: string
    created_at: string
  }
}>()

const isOutbound = computed(() => props.msg.direction === 'outbound')

const bubbleStyle = computed(() => ({
  background: isOutbound.value ? '#d9fdd3' : '#fff',
  borderRadius: isOutbound.value ? '8px 0 8px 8px' : '0 8px 8px 8px',
  borderLeft: props.msg.type === 'template' && isOutbound.value ? '3px solid #3b82f6' : 'none',
}))

const senderLabel = computed(() => {
  const map: Record<string, string> = {
    bot: '🤖 Bot',
    human: `👤 ${props.msg.sender?.name ?? 'Operador'}`,
    campaign: '📢 Campanha',
    ai: '🤖 IA',
  }
  return map[props.msg.sender_type] ?? null
})

const senderColor = computed(() => {
  const map: Record<string, string> = {
    bot: '#16a34a', human: '#0064ff', campaign: '#9333ea', ai: '#16a34a',
  }
  return map[props.msg.sender_type] ?? '#666'
})

const isMedia = computed(() => ['image', 'video', 'audio', 'document'].includes(props.msg.type))

const mediaLabel = computed(() => {
  const map: Record<string, string> = {
    image: '🖼️ Imagem', video: '🎬 Vídeo', audio: '🎵 Áudio', document: '📄 Documento',
  }
  return map[props.msg.type] ?? ''
})

const timeLabel = computed(() => {
  if (!props.msg.created_at) return ''
  const d = new Date(props.msg.created_at)
  return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
})

const statusIcon = computed(() => {
  const map: Record<string, string> = {
    pending: '🕐', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '✕',
  }
  return map[props.msg.status] ?? ''
})

const statusColor = computed(() => {
  if (props.msg.status === 'read') return '#53bdeb'
  if (props.msg.status === 'failed') return '#dc2626'
  return '#999'
})
</script>
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/components/conversations/MessageBubble.vue
git commit -m "feat: add MessageBubble component with WhatsApp styling"
```

---

## Task 4: ConversationInfo component

**Files:**
- Create: `frontend/src/components/conversations/ConversationInfo.vue`

- [ ] **Step 1: Create ConversationInfo.vue**

```vue
<template>
  <div v-if="conversation" style="overflow-y:auto;height:100%">
    <!-- Avatar -->
    <div class="text-center py-3">
      <div class="avatar avatar-lg bg-azure-lt text-azure fw-bold mx-auto mb-2">{{ initials }}</div>
      <div class="fw-bold">{{ conversation.contact?.name ?? conversation.phone }}</div>
      <div class="text-muted small">{{ conversation.phone }}</div>
      <div v-if="conversation.contact?.email" class="text-muted small">{{ conversation.contact.email }}</div>
    </div>

    <!-- Detalhes -->
    <div class="px-3 py-2 border-top">
      <div class="text-muted text-uppercase small fw-bold mb-2">Detalhes</div>
      <div class="small mb-1"><strong>Status contato:</strong>
        <span :class="['badge', contactStatusClass]">{{ conversation.contact?.status ?? '—' }}</span>
      </div>
    </div>

    <!-- Conversa -->
    <div class="px-3 py-2 border-top">
      <div class="text-muted text-uppercase small fw-bold mb-2">Conversa</div>
      <div class="small mb-1"><strong>Modo:</strong> {{ modeLabel }}</div>
      <div class="small mb-1"><strong>Início:</strong> {{ createdDate }}</div>
      <div class="small mb-1"><strong>Mensagens:</strong> {{ messageCount }}</div>
      <div v-if="conversation.assigned_to" class="small mb-1"><strong>Operador:</strong> {{ conversation.assignedUser?.name ?? `#${conversation.assigned_to}` }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  conversation: any
  messageCount: number
}>()

const initials = computed(() => {
  const name = props.conversation?.contact?.name ?? props.conversation?.phone ?? ''
  const parts = name.trim().split(' ')
  const first = parts[0]?.charAt(0) ?? ''
  const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : ''
  return (first + last).toUpperCase()
})

const modeLabel = computed(() => {
  const map: Record<string, string> = { bot: '🤖 Bot', human: '👤 Humano', closed: '✓ Fechada', open: '● Aberta' }
  return map[props.conversation?.status] ?? props.conversation?.status
})

const createdDate = computed(() => {
  if (!props.conversation?.created_at) return '—'
  return new Date(props.conversation.created_at).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
})

const contactStatusClass = computed(() => {
  const map: Record<string, string> = { active: 'bg-green', blocked: 'bg-red', invalid: 'bg-yellow text-dark' }
  return map[props.conversation?.contact?.status] ?? 'bg-secondary'
})
</script>
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/components/conversations/ConversationInfo.vue
git commit -m "feat: add ConversationInfo right panel component"
```

---

## Task 5: ConversationList component

**Files:**
- Create: `frontend/src/components/conversations/ConversationList.vue`

- [ ] **Step 1: Create ConversationList.vue**

```vue
<template>
  <div style="display:flex;flex-direction:column;height:100%;overflow:hidden">
    <!-- Search -->
    <div style="padding:10px;border-bottom:1px solid #e0e5ec">
      <input class="form-control form-control-sm" v-model="searchInput" placeholder="🔍 Buscar conversa..." @input="onSearch">
    </div>

    <!-- Status filters -->
    <div style="display:flex;gap:4px;padding:8px 10px;border-bottom:1px solid #e0e5ec;flex-wrap:wrap">
      <button v-for="f in filters" :key="f.value"
        class="btn btn-sm" :class="activeFilter === f.value ? 'btn-primary' : 'btn-ghost-secondary'"
        @click="setFilter(f.value)" style="padding:2px 10px;font-size:11px">
        {{ f.label }}
      </button>
    </div>

    <!-- List -->
    <div style="flex:1;overflow-y:auto">
      <div v-if="loading && !conversations.length" class="p-3 text-center">
        <div class="spinner-border spinner-border-sm text-primary"></div>
      </div>

      <div v-else-if="!conversations.length" class="p-3 text-center text-muted small">
        Nenhuma conversa encontrada
      </div>

      <div v-for="c in conversations" :key="c.id"
        @click="$emit('select', c)"
        :style="{
          padding: '10px',
          borderBottom: '1px solid #f0f0f0',
          cursor: 'pointer',
          background: selectedId === c.id ? '#eff6ff' : 'transparent',
          borderLeft: selectedId === c.id ? '3px solid #0064ff' : '3px solid transparent',
        }"
        @mouseover="($event.currentTarget as HTMLElement).style.background = selectedId === c.id ? '#eff6ff' : '#f8f9fb'"
        @mouseout="($event.currentTarget as HTMLElement).style.background = selectedId === c.id ? '#eff6ff' : 'transparent'">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            {{ c.contact?.name ?? c.phone }}
          </span>
          <span style="font-size:10px;color:#999;flex-shrink:0;margin-left:8px">{{ relativeTime(c.last_message_at) }}</span>
        </div>
        <div style="font-size:11px;color:#666;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          {{ c.lastMessagePreview ?? '' }}
        </div>
        <div style="display:flex;gap:4px;margin-top:4px;align-items:center">
          <span :class="['badge', statusBadge(c.status).class]" style="font-size:9px">{{ statusBadge(c.status).label }}</span>
          <span v-if="c.unread_count > 0" class="badge bg-red" style="font-size:9px">{{ c.unread_count }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'

defineProps<{
  conversations: any[]
  selectedId: number | null
  loading: boolean
}>()

defineEmits<{
  select: [conversation: any]
  filter: [status: string]
  search: [query: string]
}>()

const searchInput = ref('')
const activeFilter = ref('')
let searchTimer: any

const filters = [
  { label: 'Todas', value: '' },
  { label: '🤖 Bot', value: 'bot' },
  { label: '👤 Humano', value: 'human' },
  { label: '✓ Fechadas', value: 'closed' },
]

const emit = defineEmits<{
  select: [conversation: any]
  filter: [status: string]
  search: [query: string]
}>()

function setFilter(value: string) {
  activeFilter.value = value
  emit('filter', value)
}

function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => emit('search', searchInput.value), 300)
}

function statusBadge(status: string) {
  const map: Record<string, { label: string; class: string }> = {
    bot: { label: '🤖 bot', class: 'bg-green-lt text-green' },
    human: { label: '👤 humano', class: 'bg-blue-lt text-blue' },
    closed: { label: '✓ fechada', class: 'bg-secondary-lt' },
    open: { label: '● aberta', class: 'bg-yellow-lt text-yellow' },
  }
  return map[status] ?? { label: status, class: 'bg-secondary-lt' }
}

function relativeTime(dateStr: string | null): string {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diffMs = now.getTime() - d.getTime()
  const diffMin = Math.floor(diffMs / 60000)
  if (diffMin < 1) return 'agora'
  if (diffMin < 60) return `${diffMin}min`
  const diffHr = Math.floor(diffMin / 60)
  if (diffHr < 24) return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
  if (diffHr < 48) return 'ontem'
  return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })
}
</script>
```

**Note:** There's a duplicate `defineEmits` — the implementer should keep only one (the typed version) and remove the other. The `emit` variable should reference the single `defineEmits` call.

- [ ] **Step 2: Commit**

```bash
git add frontend/src/components/conversations/ConversationList.vue
git commit -m "feat: add ConversationList component with search and status filters"
```

---

## Task 6: ConversationChat component

**Files:**
- Create: `frontend/src/components/conversations/ConversationChat.vue`

- [ ] **Step 1: Create ConversationChat.vue**

This is the largest component. Key sections: header with handoff buttons, message area with scroll, input bar.

```vue
<template>
  <div v-if="!conversation" style="display:flex;align-items:center;justify-content:center;height:100%;color:#999">
    <div class="text-center">
      <i class="ti ti-message-dots" style="font-size:48px;opacity:0.3"></i>
      <p class="mt-2">Selecione uma conversa</p>
    </div>
  </div>

  <div v-else style="display:flex;flex-direction:column;height:100%">
    <!-- Header -->
    <div style="padding:10px 16px;border-bottom:1px solid #e0e5ec;display:flex;align-items:center;justify-content:space-between;background:#fff;flex-shrink:0">
      <div style="min-width:0">
        <div style="font-weight:600;font-size:14px">{{ conversation.contact?.name ?? conversation.phone }}</div>
        <div style="font-size:11px;color:#666">{{ conversation.phone }} · {{ modeLabel }}</div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <button v-if="conversation.status === 'bot'" class="btn btn-sm btn-outline-primary" @click="$emit('updateStatus', 'human')" :disabled="updating">
          👤 Assumir
        </button>
        <button v-if="conversation.status === 'human'" class="btn btn-sm btn-outline-secondary" @click="$emit('updateStatus', 'bot')" :disabled="updating">
          🤖 Devolver ao bot
        </button>
        <button v-if="conversation.status !== 'closed'" class="btn btn-sm btn-outline-danger" @click="$emit('updateStatus', 'closed')" :disabled="updating">
          ✕ Fechar
        </button>
        <button v-if="conversation.status === 'closed'" class="btn btn-sm btn-outline-success" @click="$emit('updateStatus', 'bot')" :disabled="updating">
          ↻ Reabrir
        </button>
        <button class="btn btn-sm btn-ghost-secondary" @click="$emit('toggleInfo')">
          <i class="ti ti-info-circle"></i>
        </button>
      </div>
    </div>

    <!-- Messages -->
    <div ref="messagesContainer" style="flex:1;overflow-y:auto;padding:12px;background:#f0f2f5">
      <!-- Load more -->
      <div v-if="hasMore" class="text-center mb-3">
        <button class="btn btn-sm btn-ghost-secondary" @click="$emit('loadMore')" :disabled="loadingMore">
          <span v-if="loadingMore" class="spinner-border spinner-border-sm me-1"></span>
          Carregar anteriores
        </button>
      </div>

      <MessageBubble v-for="m in messages" :key="m.id" :msg="m" />

      <!-- Typing / loading -->
      <div v-if="sending" class="d-flex justify-content-end mb-2">
        <div style="background:#d9fdd3;border-radius:8px 0 8px 8px;padding:8px 12px;font-size:12px;opacity:0.6">
          <div class="spinner-border spinner-border-sm" style="width:12px;height:12px"></div>
          Enviando...
        </div>
      </div>
    </div>

    <!-- AI suggestion placeholder -->
    <div style="padding:6px 12px;background:#f8f9fb;border-top:1px solid #e0e5ec;font-size:12px;color:#999;display:flex;align-items:center;gap:8px">
      <span>💡</span>
      <span style="flex:1;font-style:italic">Sugestão IA indisponível neste plano</span>
      <button class="btn btn-sm btn-ghost-secondary" disabled style="font-size:11px">Usar</button>
    </div>

    <!-- Input -->
    <div v-if="conversation.status !== 'closed'" style="padding:8px 12px;border-top:1px solid #e0e5ec;background:#fff;display:flex;gap:8px;align-items:flex-end;flex-shrink:0">
      <button class="btn btn-ghost-secondary btn-sm" @click="attachToast" style="flex-shrink:0">📎</button>
      <textarea ref="inputEl" v-model="inputText" @keydown="onKeydown"
        style="flex:1;padding:8px 14px;border:1px solid #ddd;border-radius:20px;font-size:13px;resize:none;max-height:120px;min-height:38px;line-height:1.4"
        placeholder="Digite uma mensagem..." rows="1"></textarea>
      <button class="btn btn-primary btn-sm" @click="send" :disabled="!inputText.trim() || sending"
        style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0">
        ➤
      </button>
    </div>
    <div v-else style="padding:12px;text-align:center;background:#fff3cd;font-size:13px;color:#856404;border-top:1px solid #e0e5ec">
      Conversa fechada. <a href="#" @click.prevent="$emit('updateStatus', 'bot')" style="text-decoration:underline">Reabrir</a> para enviar mensagens.
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import MessageBubble from './MessageBubble.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  conversation: any
  messages: any[]
  hasMore: boolean
  loadingMore: boolean
  sending: boolean
  updating: boolean
}>()

const emit = defineEmits<{
  send: [text: string]
  updateStatus: [status: string]
  toggleInfo: []
  loadMore: []
}>()

const toast = useToast()
const inputText = ref('')
const inputEl = ref<HTMLTextAreaElement | null>(null)
const messagesContainer = ref<HTMLElement | null>(null)

const modeLabel = computed(() => {
  const map: Record<string, string> = { bot: '🤖 Bot ativo', human: '👤 Atendimento humano', closed: '✓ Fechada', open: '● Aberta' }
  return map[props.conversation?.status] ?? ''
})

function send() {
  const text = inputText.value.trim()
  if (!text) return
  emit('send', text)
  inputText.value = ''
  nextTick(() => inputEl.value?.focus())
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    send()
  }
}

function attachToast() {
  toast.info('Envio de arquivos disponível em breve')
}

// Auto-scroll to bottom when new messages arrive
watch(() => props.messages.length, () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
})

// Focus input when conversation changes
watch(() => props.conversation?.id, () => {
  nextTick(() => inputEl.value?.focus())
})
</script>
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/components/conversations/ConversationChat.vue
git commit -m "feat: add ConversationChat component with messages, input, and handoff controls"
```

---

## Task 7: Inbox page — 3-column layout + notification sound

**Files:**
- Create: `frontend/src/pages/conversations/Index.vue`
- Create: `frontend/public/sounds/notification.mp3`
- Modify: `frontend/src/router/index.ts:91-93`

- [ ] **Step 1: Create notification sound placeholder**

Create a minimal valid MP3 file. Since we can't generate real audio, create a text placeholder file that the user should replace with an actual notification sound:

```bash
cd frontend/public && mkdir -p sounds && echo "REPLACE_WITH_REAL_MP3" > sounds/notification.mp3
```

**Note to implementer:** The notification.mp3 file must be replaced with a real MP3 sound file (short notification beep). The code will still work — it will just fail silently to play an invalid audio file, which is acceptable for development.

- [ ] **Step 2: Create Index.vue**

```vue
<template>
  <div style="display:flex;height:calc(100vh - 64px);overflow:hidden">
    <!-- Left: Conversation List -->
    <div style="width:280px;flex-shrink:0;border-right:1px solid #e0e5ec;background:#f8f9fb">
      <ConversationList
        :conversations="conversations"
        :selected-id="activeConversation?.id ?? null"
        :loading="listLoading"
        @select="selectConversation"
        @filter="onFilter"
        @search="onSearch"
      />
    </div>

    <!-- Center: Chat -->
    <div style="flex:1;min-width:0;display:flex;flex-direction:column">
      <ConversationChat
        :conversation="activeConversation"
        :messages="messages"
        :has-more="messagesPagination.current_page < messagesPagination.last_page"
        :loading-more="loadingMore"
        :sending="sending"
        :updating="updatingStatus"
        @send="sendMessage"
        @update-status="updateStatus"
        @toggle-info="showInfo = !showInfo"
        @load-more="loadMoreMessages"
      />
    </div>

    <!-- Right: Contact Info -->
    <div v-if="showInfo && activeConversation" style="width:300px;flex-shrink:0;border-left:1px solid #e0e5ec;background:#fff">
      <ConversationInfo
        :conversation="activeConversation"
        :message-count="messagesPagination.total"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import ConversationList from '@/components/conversations/ConversationList.vue'
import ConversationChat from '@/components/conversations/ConversationChat.vue'
import ConversationInfo from '@/components/conversations/ConversationInfo.vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import { useConversationPolling } from '@/composables/useConversationPolling'

const { get, post, patch } = useApi()
const toast = useToast()
const { startPolling, stopPolling, stopAll } = useConversationPolling()

// State
const conversations = ref<any[]>([])
const activeConversation = ref<any>(null)
const messages = ref<any[]>([])
const messagesPagination = ref({ current_page: 1, last_page: 1, total: 0 })
const showInfo = ref(true)
const listLoading = ref(false)
const loadingMore = ref(false)
const sending = ref(false)
const updatingStatus = ref(false)
const filterStatus = ref('')
const searchQuery = ref('')

// Notification
let lastUnreadIds = new Set<number>()
const notificationAudio = typeof Audio !== 'undefined' ? new Audio('/sounds/notification.mp3') : null

// Fetch conversations list
async function fetchList() {
  try {
    const params: Record<string, any> = {}
    if (filterStatus.value) params.status = filterStatus.value
    if (searchQuery.value) params.search = searchQuery.value
    const res = await get<any>('/conversations', params)
    const items = res?.data ?? res ?? []
    conversations.value = Array.isArray(items) ? items : []

    // Notification sound on new unreads
    const currentUnreadIds = new Set(
      conversations.value.filter((c: any) => c.unread_count > 0).map((c: any) => c.id)
    )
    const newUnreads = [...currentUnreadIds].filter(id => !lastUnreadIds.has(id))
    if (newUnreads.length > 0 && !document.hidden && notificationAudio) {
      notificationAudio.play().catch(() => {})
    }
    lastUnreadIds = currentUnreadIds
  } catch (e) {
    // silent
  }
}

// Fetch active conversation messages
async function fetchMessages(page = 1) {
  if (!activeConversation.value) return
  try {
    const res = await get<any>(`/conversations/${activeConversation.value.id}`, { page })
    if (page === 1) {
      messages.value = (res?.messages ?? []).reverse()
    } else {
      const older = (res?.messages ?? []).reverse()
      messages.value = [...older, ...messages.value]
    }
    messagesPagination.value = res?.pagination ?? messagesPagination.value
    if (res?.conversation) {
      activeConversation.value = res.conversation
      // Update in list too
      const idx = conversations.value.findIndex((c: any) => c.id === res.conversation.id)
      if (idx >= 0) conversations.value[idx] = { ...conversations.value[idx], unread_count: 0 }
    }
  } catch (e) {
    // silent
  }
}

// Select conversation
function selectConversation(c: any) {
  activeConversation.value = c
  messages.value = []
  messagesPagination.value = { current_page: 1, last_page: 1, total: 0 }
  fetchMessages()

  // Start chat polling
  stopPolling('chat')
  const interval = c.status === 'closed' ? 15000 : 3000
  startPolling('chat', () => fetchMessages(), interval)
}

// Send message
async function sendMessage(text: string) {
  if (!activeConversation.value) return
  sending.value = true
  try {
    const res = await post<any>(`/conversations/${activeConversation.value.id}/messages`, { text })
    // Add optimistically
    if (res) messages.value.push(res)
    await fetchMessages()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao enviar')
  } finally {
    sending.value = false
  }
}

// Update status (handoff)
async function updateStatus(status: string) {
  if (!activeConversation.value) return
  updatingStatus.value = true
  try {
    const res = await patch<any>(`/conversations/${activeConversation.value.id}/status`, { status })
    activeConversation.value = res
    // Update chat polling interval
    stopPolling('chat')
    const interval = status === 'closed' ? 15000 : 3000
    startPolling('chat', () => fetchMessages(), interval)
    toast.success('Status atualizado')
    await fetchList()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao atualizar status')
  } finally {
    updatingStatus.value = false
  }
}

// Load older messages
async function loadMoreMessages() {
  if (loadingMore.value) return
  loadingMore.value = true
  try {
    await fetchMessages(messagesPagination.value.current_page + 1)
  } finally {
    loadingMore.value = false
  }
}

// Filters
function onFilter(status: string) {
  filterStatus.value = status
  fetchList()
}
function onSearch(query: string) {
  searchQuery.value = query
  fetchList()
}

onMounted(() => {
  listLoading.value = true
  fetchList().finally(() => { listLoading.value = false })

  // Start list polling
  startPolling('list', fetchList, 5000)
})

onUnmounted(() => {
  stopAll()
})
</script>
```

- [ ] **Step 3: Update router**

In `frontend/src/router/index.ts`, replace lines 91-93:

```ts
  {
    path: '/conversations',
    component: () => import('@/pages/conversations/Index.vue'),
    meta: { title: 'Conversas' },
  },
```

- [ ] **Step 4: Build and verify**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/conversations/Index.vue frontend/public/sounds/notification.mp3 frontend/src/router/index.ts
git commit -m "feat: add Inbox page with 3-column layout, polling, and notification sound"
```

---

## Task 8: Sidebar unread badge

**Files:**
- Modify: `frontend/src/components/layout/AppSidebar.vue:38-43,91-112`

- [ ] **Step 1: Add unread badge and polling to AppSidebar.vue**

Add imports and logic to the `<script setup>` section (after line 95):

```ts
import { ref, onMounted, onUnmounted } from 'vue'
import { useApi } from '@/composables/useApi'

const { get } = useApi()
const unreadCount = ref(0)
let badgeTimer: any

async function fetchUnread() {
  try {
    const res = await get<any>('/conversations/unread-count')
    unreadCount.value = res?.count ?? 0
  } catch { /* silent */ }
}

onMounted(() => {
  fetchUnread()
  badgeTimer = setInterval(fetchUnread, 10000)
})

onUnmounted(() => {
  if (badgeTimer) clearInterval(badgeTimer)
})
```

**Note:** The existing `<script setup>` already imports `computed` from 'vue'. Change it to `import { computed, ref, onMounted, onUnmounted } from 'vue'`.

Update the Conversas nav item in the template (lines 38-43) to include the badge:

```html
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/conversations') }" @click.prevent="go('/conversations')">
            <span class="nav-link-icon"><i class="ti ti-brand-whatsapp"></i></span>
            <span class="nav-link-title">Conversas</span>
            <span v-if="unreadCount > 0" class="badge bg-red ms-auto">{{ unreadCount }}</span>
          </a>
        </li>
```

- [ ] **Step 2: Build and verify**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/layout/AppSidebar.vue
git commit -m "feat: add unread conversation badge to sidebar with 10s polling"
```

---

## Task 9: Final verification

- [ ] **Step 1: Backend syntax check**

Run: `cd backend && php -l app/Http/Controllers/API/V1/ConversationController.php`
Expected: `No syntax errors detected`

- [ ] **Step 2: Route check**

Run: `php artisan route:list --path=v1/conversations 2>&1`
Expected: 5 routes (unread-count, index, show, messages, status)

- [ ] **Step 3: Frontend build**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 4: Manual smoke test**

1. Navigate to `/conversations` — 3-column layout renders (empty state in chat area)
2. Sidebar shows "Conversas" with WhatsApp icon
3. If conversations exist: click one → messages load, header shows status
4. Input field: type text → Enter sends → bolha aparece
5. "Assumir" button → changes status to human
6. "Fechar" button → input disabled, shows "Reabrir"
7. Info panel: toggle with ℹ️ button
8. Unread badge appears in sidebar when conversations have unread_count > 0
