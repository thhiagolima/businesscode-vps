<template>
  <div class="funnel-node" style="border:2px solid #06b6d4;border-radius:8px;padding:10px 16px;background:var(--bc-gray);min-width:140px;color:var(--bc-text);box-shadow:0 4px 12px rgba(0,0,0,0.3)">
    <Handle type="target" :position="Position.Top" />
    <div style="font-size:10px;color:#06b6d4;font-weight:600;text-transform:uppercase">🤖 IA Responde</div>
    <div style="font-size:12px;margin-top:2px">{{ data.label || 'Resposta automática' }}</div>
    <Handle type="source" :position="Position.Bottom" />
  </div>
</template>

<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core'
defineProps<{ data: any }>()
</script>
