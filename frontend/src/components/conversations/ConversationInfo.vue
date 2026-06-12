<template>
  <div v-if="conversation" style="overflow-y:auto;height:100%">
    <div class="text-center py-3">
      <div class="avatar avatar-lg bg-azure-lt text-azure fw-bold mx-auto mb-2">{{ initials }}</div>
      <div class="fw-bold">{{ conversation.contact?.name ?? conversation.phone }}</div>
      <div class="text-muted small">{{ conversation.phone }}</div>
      <div v-if="conversation.contact?.email" class="text-muted small">{{ conversation.contact.email }}</div>
    </div>
    <div class="px-3 py-2 border-top">
      <div class="text-muted text-uppercase small fw-bold mb-2">Detalhes</div>
      <div class="small mb-1"><strong>Status contato:</strong>
        <span :class="['badge', contactStatusClass]">{{ conversation.contact?.status ?? '—' }}</span>
      </div>
    </div>
    <div class="px-3 py-2 border-top">
      <div class="text-muted text-uppercase small fw-bold mb-2">Conversa</div>
      <div class="small mb-1"><strong>Modo:</strong> {{ modeLabel }}</div>
      <div class="small mb-1"><strong>Início:</strong> {{ createdDate }}</div>
      <div class="small mb-1"><strong>Mensagens:</strong> {{ messageCount }}</div>
      <div v-if="conversation.assigned_to" class="small mb-1"><strong>Operador:</strong> #{{ conversation.assigned_to }}</div>
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
