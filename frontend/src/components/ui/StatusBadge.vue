<template>
  <span class="bc-badge" :class="`bc-badge-${resolvedColor}`">
    <span v-if="dot" class="bc-badge-dot"></span>
    <i v-if="icon" :class="['ti', icon]" style="font-size:0.7rem"></i>
    {{ label }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  label: string
  color?: 'primary' | 'success' | 'danger' | 'warning' | 'info' | 'secondary'
  variant?: 'filled' | 'light' | 'outline'
  dot?: boolean
  icon?: string
  status?: string
}>(), {
  color: 'secondary',
  variant: 'light',
  dot: false,
})

// Auto-resolve color from status if not explicitly set
const statusColorMap: Record<string, string> = {
  // Campaign statuses
  draft: 'warning', rascunho: 'warning',
  scheduled: 'info', agendada: 'info',
  processing: 'primary', processando: 'primary',
  running: 'primary',
  completed: 'success', concluída: 'success', concluida: 'success',
  canceled: 'danger', cancelada: 'danger',
  failed: 'danger', falhou: 'danger',
  // Generic statuses
  active: 'success', ativo: 'success',
  inactive: 'secondary', inativo: 'secondary',
  blocked: 'danger', bloqueado: 'danger',
  invalid: 'warning', inválido: 'warning',
  // Tenant
  trial: 'info',
  suspended: 'danger',
  // AI Persona
  pending_approval: 'warning',
  approved: 'success',
  rejected: 'danger',
  // Conversation
  open: 'primary',
  bot: 'success',
  human: 'info',
  closed: 'secondary',
  // Funnel
  paused: 'warning',
  // Channel types (for channel badges)
  sms: 'primary',
  email: 'info',
  voice: 'warning',
  whatsapp: 'success',
  // Dispatch statuses
  sent: 'primary',
  delivered: 'success',
  read: 'success',
  pending: 'secondary',
}

const resolvedColor = computed(() => {
  if (props.color !== 'secondary') return `${props.color}-${props.variant}`
  if (props.status) {
    const c = statusColorMap[props.status.toLowerCase()] ?? 'secondary'
    return `${c}-${props.variant}`
  }
  return `secondary-${props.variant}`
})
</script>

<style scoped>
.bc-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  padding: 0.3em 0.7em;
  border-radius: 6px;
  white-space: nowrap;
}
.bc-badge-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}

/* Light variants — BusinessCode palette */
.bc-badge-primary-light  { background: rgba(0,100,255,0.07); color: #0054d9; }
.bc-badge-success-light  { background: rgba(16,185,129,0.07); color: #0d9668; }
.bc-badge-danger-light   { background: rgba(220,38,38,0.07); color: #c82020; }
.bc-badge-warning-light  { background: rgba(234,179,8,0.07); color: #a16207; }
.bc-badge-info-light     { background: rgba(59,130,246,0.07); color: #2563eb; }
.bc-badge-secondary-light{ background: rgba(108,114,147,0.1); color: var(--bc-text-muted); }

/* Filled variants */
.bc-badge-primary-filled  { background: #0064ff; color: #fff; }
.bc-badge-success-filled  { background: #0d9668; color: #fff; }
.bc-badge-danger-filled   { background: #dc2626; color: #fff; }
.bc-badge-warning-filled  { background: #eab308; color: #78350f; }
.bc-badge-info-filled     { background: #2563eb; color: #fff; }
.bc-badge-secondary-filled{ background: #6c7293; color: #fff; }

/* Outline variants */
.bc-badge-primary-outline  { border: 1px solid rgba(0,100,255,0.3); color: #0054d9; background: transparent; }
.bc-badge-success-outline  { border: 1px solid rgba(16,185,129,0.3); color: #0d9668; background: transparent; }
.bc-badge-danger-outline   { border: 1px solid rgba(220,38,38,0.3); color: #c82020; background: transparent; }
.bc-badge-warning-outline  { border: 1px solid rgba(234,179,8,0.3); color: #a16207; background: transparent; }
.bc-badge-info-outline     { border: 1px solid rgba(59,130,246,0.3); color: #2563eb; background: transparent; }
.bc-badge-secondary-outline{ border: 1px solid rgba(108,114,147,0.3); color: var(--bc-text-muted); background: transparent; }
</style>
