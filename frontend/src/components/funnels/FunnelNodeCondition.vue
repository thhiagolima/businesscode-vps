<template>
  <div style="background:var(--bc-gray);border:2px solid #3b82f6;border-radius:8px;padding:8px 16px;min-width:150px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.3);color:var(--bc-text)" @click="$emit('select')">
    <Handle type="target" :position="Position.Top" />
    <div style="font-size:10px;color:#3b82f6;font-weight:700;text-transform:uppercase">Condicao</div>
    <div style="font-size:11px">{{ label }}</div>
    <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:10px">
      <span style="color:#16a34a">Sim</span>
      <span style="color:#dc2626">Nao</span>
    </div>
    <Handle type="source" :position="Position.Bottom" id="yes" :style="{ left: '30%' }" />
    <Handle type="source" :position="Position.Bottom" id="no" :style="{ left: '70%' }" />
  </div>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { Handle, Position } from '@vue-flow/core'
const props = defineProps<{ data: any }>()
defineEmits(['select'])
const typeMap: Record<string, string> = { replied: 'Respondeu?', keyword: 'Palavra-chave', timeout: 'Timeout' }
const label = computed(() => typeMap[props.data.config?.condition_type ?? 'replied'] ?? 'Configurar...')
</script>
