<template>
  <div style="background:var(--bc-gray);border:2px solid #f59e0b;border-radius:8px;padding:8px 16px;min-width:120px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.3);color:var(--bc-text)" @click="$emit('select')">
    <Handle type="target" :position="Position.Top" />
    <div style="font-size:10px;color:#f59e0b;font-weight:700;text-transform:uppercase">Espera</div>
    <div style="font-size:11px">{{ label }}</div>
    <Handle type="source" :position="Position.Bottom" />
  </div>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'
const props = defineProps<{ data: any }>()
defineEmits(['select'])
const unitMap: Record<string, string> = { minutes: 'min', hours: 'h', days: 'd' }
const label = computed(() => {
  const cfg = props.data.config ?? {}
  const d = cfg.duration ?? 1
  const u = unitMap[cfg.unit ?? 'hours'] ?? 'h'
  return `${d} ${u}`
})
</script>
