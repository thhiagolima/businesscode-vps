<template>
  <div class="d-flex mb-2" :class="isOutbound ? 'justify-content-end' : ''">
    <div :style="bubbleStyle" style="max-width:75%;padding:6px 10px;font-size:13px;box-shadow:0 1px 1px rgba(0,0,0,0.05)">
      <div v-if="isOutbound && senderLabel" style="font-size:10px;font-weight:600;margin-bottom:2px" :style="{ color: senderColor }">
        {{ senderLabel }}
      </div>
      <div v-if="msg.type === 'template'" class="mb-1">
        <span style="font-size:10px;background:rgba(0,100,255,0.12);color:var(--bc-primary);padding:1px 6px;border-radius:3px">📋 {{ msg.template_name }}</span>
      </div>
      <!-- Media previews -->
      <img v-if="msg.type === 'image' && msg.media_url" :src="msg.media_url"
           style="max-width:240px;border-radius:6px;cursor:pointer"
           @click="openMedia"
           loading="lazy" alt="Imagem">

      <div v-else-if="msg.type === 'video' && msg.media_url" style="position:relative;max-width:240px;cursor:pointer" @click="openMedia">
        <video :src="msg.media_url" style="max-width:240px;border-radius:6px" preload="metadata"></video>
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.5);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center">
          <i class="ti ti-player-play" style="color:#fff;font-size:20px"></i>
        </div>
      </div>

      <audio v-else-if="msg.type === 'audio' && msg.media_url" :src="msg.media_url" controls style="max-width:240px"></audio>

      <a v-else-if="msg.type === 'document' && msg.media_url" :href="msg.media_url" target="_blank" class="d-flex align-items-center gap-2 text-decoration-none" style="padding:8px;background:rgba(0,0,0,0.05);border-radius:6px">
        <i class="ti ti-file-download" style="font-size:24px"></i>
        <span style="font-size:12px">{{ msg.content || 'Documento' }}</span>
      </a>

      <div v-else-if="msg.type === 'location'" class="d-flex align-items-center gap-2" style="padding:8px;background:rgba(0,0,0,0.05);border-radius:6px">
        <i class="ti ti-map-pin" style="font-size:24px;color:#dc2626"></i>
        <span style="font-size:12px">Localização compartilhada</span>
      </div>

      <!-- Text content / caption -->
      <div v-if="msg.content && msg.type !== 'document'" style="white-space:pre-wrap;word-break:break-word">{{ msg.content }}</div>
      <div style="font-size:9px;color:var(--bc-text-muted);text-align:right;margin-top:2px;display:flex;justify-content:flex-end;align-items:center;gap:3px">
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
  background: isOutbound.value ? 'rgba(0,100,255,0.12)' : 'var(--bc-gray-soft)',
  color: 'var(--bc-text)',
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
  const map: Record<string, string> = { bot: '#16a34a', human: '#0064ff', campaign: '#9333ea', ai: '#16a34a' }
  return map[props.msg.sender_type] ?? '#666'
})

function openMedia() {
  if (props.msg.media_url) {
    window.open(props.msg.media_url, '_blank')
  }
}

const timeLabel = computed(() => {
  if (!props.msg.created_at) return ''
  const d = new Date(props.msg.created_at)
  return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
})

const statusIcon = computed(() => {
  const map: Record<string, string> = { pending: '🕐', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '✕' }
  return map[props.msg.status] ?? ''
})

const statusColor = computed(() => {
  if (props.msg.status === 'read') return '#53bdeb'
  if (props.msg.status === 'failed') return 'var(--bc-danger)'
  return 'var(--bc-text-muted)'
})
</script>
