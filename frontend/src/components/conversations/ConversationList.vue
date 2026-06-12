<template>
  <div style="display:flex;flex-direction:column;height:100%;overflow:hidden">
    <div style="padding:10px;border-bottom:1px solid #e0e5ec">
      <input class="form-control form-control-sm" v-model="searchInput" placeholder="🔍 Buscar conversa..." @input="onSearch">
    </div>
    <div style="display:flex;gap:4px;padding:8px 10px;border-bottom:1px solid #e0e5ec;flex-wrap:wrap">
      <button v-for="f in filters" :key="f.value"
        class="btn btn-sm" :class="activeFilter === f.value ? 'btn-primary' : 'btn-ghost-secondary'"
        @click="setFilter(f.value)" style="padding:2px 10px;font-size:11px">
        {{ f.label }}
      </button>
    </div>
    <div style="flex:1;overflow-y:auto">
      <div v-if="loading && !conversations.length" class="p-3 text-center">
        <div class="spinner-border spinner-border-sm text-primary"></div>
      </div>
      <div v-else-if="!conversations.length" class="p-3 text-center text-muted small">
        Nenhuma conversa encontrada
      </div>
      <div v-for="c in conversations" :key="c.id"
        @click="emit('select', c)"
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
          <span :class="['badge', statusBadge(c.status).cls]" style="font-size:9px">{{ statusBadge(c.status).label }}</span>
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

const emit = defineEmits<{
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

function setFilter(value: string) {
  activeFilter.value = value
  emit('filter', value)
}

function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => emit('search', searchInput.value), 300)
}

function statusBadge(status: string) {
  const map: Record<string, { label: string; cls: string }> = {
    bot: { label: '🤖 bot', cls: 'bg-green-lt text-green' },
    human: { label: '👤 humano', cls: 'bg-blue-lt text-blue' },
    closed: { label: '✓ fechada', cls: 'bg-secondary-lt' },
    open: { label: '● aberta', cls: 'bg-yellow-lt text-yellow' },
  }
  return map[status] ?? { label: status, cls: 'bg-secondary-lt' }
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
