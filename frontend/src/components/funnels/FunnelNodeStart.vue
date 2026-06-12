<template>
  <div style="background:var(--bc-gray);border:2px solid #16a34a;border-radius:8px;padding:8px 16px;min-width:120px;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.3);color:var(--bc-text)" @click="$emit('select')">
    <div style="font-size:10px;color:#16a34a;font-weight:700;text-transform:uppercase">Inicio</div>
    <div style="font-size:12px;font-weight:600">{{ data.label ?? 'Trigger' }}</div>
    <Handle type="source" :position="Position.Bottom" />
  </div>
</template>
<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core'
defineProps<{ data: any }>()
defineEmits(['select'])
</script>
