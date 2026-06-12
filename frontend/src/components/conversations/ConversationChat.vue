<template>
  <div v-if="!conversation" style="display:flex;align-items:center;justify-content:center;height:100%;color:#999">
    <div class="text-center">
      <i class="ti ti-message-dots" style="font-size:48px;opacity:0.3"></i>
      <p class="mt-2">Selecione uma conversa</p>
    </div>
  </div>

  <div v-else style="display:flex;flex-direction:column;height:100%">
    <!-- Header -->
    <div style="padding:10px 16px;border-bottom:1px solid var(--bc-outline);display:flex;align-items:center;justify-content:space-between;background:var(--bc-dark-surface);flex-shrink:0">
      <div style="min-width:0">
        <div style="font-weight:600;font-size:14px">{{ conversation.contact?.name ?? conversation.phone }}</div>
        <div style="font-size:11px;color:var(--bc-text-muted)">{{ conversation.phone }} · {{ modeLabel }}</div>
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
    <div ref="messagesContainer" style="flex:1;overflow-y:auto;padding:12px;background:var(--bc-dark-lighter)">
      <div v-if="hasMore" class="text-center mb-3">
        <button class="btn btn-sm btn-ghost-secondary" @click="$emit('loadMore')" :disabled="loadingMore">
          <span v-if="loadingMore" class="spinner-border spinner-border-sm me-1"></span>
          Carregar anteriores
        </button>
      </div>
      <MessageBubble v-for="m in messages" :key="m.id" :msg="m" />
      <div v-if="sending" class="d-flex justify-content-end mb-2">
        <div style="background:var(--bc-primary-subtle);border-radius:8px 0 8px 8px;padding:8px 12px;font-size:12px;opacity:0.6;color:var(--bc-text)">
          <div class="spinner-border spinner-border-sm" style="width:12px;height:12px"></div>
          Enviando...
        </div>
      </div>
    </div>

    <!-- Typing indicator -->
    <div v-if="conversation?.metadata?.ai_processing" class="d-flex align-items-center gap-2 px-3 py-2" style="background:var(--bc-dark-surface)">
      <div class="typing-dots">
        <span></span><span></span><span></span>
      </div>
      <span style="font-size:11px;color:var(--bc-text-muted)">IA está digitando...</span>
    </div>

    <!-- AI suggestion -->
    <div v-if="aiSuggestion" style="padding:8px 12px;background:rgba(16,185,129,0.08);border-top:1px solid rgba(16,185,129,0.15);font-size:13px;color:#10b981;display:flex;align-items:center;gap:8px">
      <span>💡</span>
      <span style="flex:1">{{ aiSuggestion }}</span>
      <button class="btn btn-sm btn-success" @click="useSuggestion" :disabled="sending" style="font-size:11px">Usar</button>
      <button class="btn btn-sm btn-ghost-secondary" @click="ignoreSuggestion" style="font-size:11px">Ignorar</button>
    </div>
    <div v-else style="padding:6px 12px;background:var(--bc-dark-surface);border-top:1px solid var(--bc-outline);font-size:12px;color:var(--bc-text-muted);display:flex;align-items:center;gap:8px">
      <span>💡</span>
      <span style="flex:1;font-style:italic">Sugestão IA indisponível</span>
    </div>

    <!-- Input -->
    <div v-if="conversation.status !== 'closed'" style="padding:8px 12px;border-top:1px solid var(--bc-outline);background:var(--bc-dark-surface);display:flex;gap:8px;align-items:flex-end;flex-shrink:0">
      <button class="btn btn-ghost-secondary btn-sm" @click="attachToast" style="flex-shrink:0">📎</button>
      <textarea ref="inputEl" v-model="inputText" @keydown="onKeydown"
        style="flex:1;padding:8px 14px;border:none;border-radius:20px;font-size:13px;resize:none;max-height:120px;min-height:38px;line-height:1.4;background:var(--bc-gray-soft);color:var(--bc-text)"
        placeholder="Digite uma mensagem..." rows="1"></textarea>
      <button class="btn btn-primary btn-sm" @click="send" :disabled="!inputText.trim() || sending"
        style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0">
        ➤
      </button>
    </div>
    <div v-else style="padding:12px;text-align:center;background:rgba(245,158,11,0.08);font-size:13px;color:var(--bc-warning);border-top:1px solid var(--bc-outline)">
      Conversa fechada. <a href="#" @click.prevent="$emit('updateStatus', 'bot')" style="text-decoration:underline;color:var(--bc-primary)">Reabrir</a> para enviar mensagens.
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
  clearSuggestion: []
}>()

const toast = useToast()
const inputText = ref('')
const inputEl = ref<HTMLTextAreaElement | null>(null)
const messagesContainer = ref<HTMLElement | null>(null)

const aiSuggestion = computed(() => props.conversation?.metadata?.ai_suggestion ?? null)

function useSuggestion() {
  if (aiSuggestion.value) {
    emit('send', aiSuggestion.value)
  }
}

function ignoreSuggestion() {
  emit('clearSuggestion')
}

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

watch(() => props.messages.length, () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
})

watch(() => props.conversation?.id, () => {
  nextTick(() => inputEl.value?.focus())
})
</script>

<style scoped>
.typing-dots {
  display: flex;
  gap: 3px;
  align-items: center;
}
.typing-dots span {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--bc-text-muted);
  animation: typing-bounce 1.4s infinite;
}
.typing-dots span:nth-child(2) { animation-delay: 0.2s; }
.typing-dots span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typing-bounce {
  0%, 80%, 100% { transform: translateY(0); opacity: 0.4; }
  40% { transform: translateY(-4px); opacity: 1; }
}
</style>
