<template>
  <div class="funnel-toolbar">
    <div class="funnel-toolbar__heading">Blocos</div>
    <div
      v-for="block in blocks"
      :key="block.type"
      class="funnel-toolbar__block"
      :style="{ '--block-color': block.color, '--block-bg': block.bg }"
      draggable="true"
      :aria-label="`Arraste para o canvas: bloco ${block.label}`"
      :title="block.tip"
      @dragstart="onDragStart($event, block.type)"
    >
      <div class="funnel-toolbar__icon">
        <i :class="`ti ${block.icon}`"></i>
      </div>
      <span class="funnel-toolbar__label">{{ block.label }}</span>
    </div>
    <div class="funnel-toolbar__hint">
      <i class="ti ti-info-circle me-1"></i>
      Arraste um bloco para o canvas.
    </div>
  </div>
</template>

<script setup lang="ts">
const blocks = [
  { type: 'message',        label: 'Mensagem',     icon: 'ti-message',      color: '#22c55e', bg: 'rgba(34,197,94,0.12)',  tip: 'Envia uma mensagem ao contato' },
  { type: 'wait',           label: 'Espera',       icon: 'ti-clock',        color: '#f59e0b', bg: 'rgba(245,158,11,0.12)', tip: 'Aguarda um tempo antes do próximo bloco' },
  { type: 'condition',      label: 'Condição',     icon: 'ti-arrows-split', color: '#3b82f6', bg: 'rgba(59,130,246,0.12)', tip: 'Bifurca o fluxo conforme uma regra' },
  { type: 'tag',            label: 'Tag',          icon: 'ti-tag',          color: '#ec4899', bg: 'rgba(236,72,153,0.12)', tip: 'Marca o contato com uma tag' },
  { type: 'transfer_human', label: 'Transferir',   icon: 'ti-user-check',   color: '#8b5cf6', bg: 'rgba(139,92,246,0.12)', tip: 'Encaminha para atendimento humano' },
  { type: 'ai_reply',       label: 'IA Responde',  icon: 'ti-robot',        color: '#06b6d4', bg: 'rgba(6,182,212,0.12)',  tip: 'Resposta automática com IA' },
]

function onDragStart(event: DragEvent, type: string) {
  event.dataTransfer?.setData('application/funnel-node-type', type)
  event.dataTransfer!.effectAllowed = 'move'
}
</script>

<style scoped>
.funnel-toolbar {
  padding: 14px 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.funnel-toolbar__heading {
  font-family: 'JetBrains Mono', monospace;
  font-size: 0.62rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--bc-text-muted);
  padding: 0 4px 4px;
}
.funnel-toolbar__block {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 10px 6px;
  background: var(--block-bg);
  border: 1px solid transparent;
  border-radius: 10px;
  cursor: grab;
  transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
  user-select: none;
}
.funnel-toolbar__block:hover {
  border-color: var(--block-color);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}
.funnel-toolbar__block:active { cursor: grabbing; }
.funnel-toolbar__block:focus-visible {
  outline: 2px solid var(--block-color);
  outline-offset: 2px;
}
.funnel-toolbar__icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(255,255,255,0.04);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--block-color);
  font-size: 1.1rem;
}
.funnel-toolbar__label {
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--bc-text);
  text-align: center;
  line-height: 1.2;
}
.funnel-toolbar__hint {
  margin-top: 0.5rem;
  font-size: 0.65rem;
  color: var(--bc-text-muted);
  text-align: center;
  line-height: 1.4;
  padding: 0 4px;
}
</style>
