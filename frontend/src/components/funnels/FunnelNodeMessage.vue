<template>
  <div style="background:var(--bc-gray);border:2px solid #16a34a;border-radius:8px;padding:8px 16px;min-width:140px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.3);color:var(--bc-text)" @click="$emit('select')">
    <Handle type="target" :position="Position.Top" />
    <div style="font-size:10px;color:#16a34a;font-weight:700;text-transform:uppercase">Mensagem</div>
    <div style="font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
      {{ preview }}
    </div>
    <Handle type="source" :position="Position.Bottom" />
  </div>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'
const props = defineProps<{ data: any }>()
defineEmits(['select'])
const preview = computed(() => {
  const cfg = props.data.config ?? {}
  if (cfg.message_type === 'template') return `Template: ${cfg.template_name ?? 'Template'}`
  const text = cfg.text ?? ''
  return text.length > 40 ? text.slice(0, 40) + '...' : text || 'Configurar...'
})
</script>
