<template>
  <div class="kpi-card" :class="[`kpi-${color}`, { 'kpi-clickable': clickable }]" @click="clickable && $emit('click')">
    <div class="kpi-header">
      <span class="kpi-label">{{ label }}</span>
      <div v-if="icon" class="kpi-icon" :class="`kpi-icon-${color}`">
        <i :class="['ti', icon]"></i>
      </div>
    </div>
    <div class="kpi-value">
      <span class="kpi-number" :title="valueTitle">{{ formattedValue }}</span>
      <span v-if="suffix" class="kpi-suffix">{{ suffix }}</span>
    </div>
    <div v-if="subtitle || $slots.subtitle" class="kpi-subtitle">
      <slot name="subtitle">{{ subtitle }}</slot>
    </div>
    <div v-if="$slots.default" class="kpi-extra">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  label: string
  value: number | string
  suffix?: string
  subtitle?: string
  icon?: string
  color?: 'primary' | 'success' | 'danger' | 'warning' | 'info'
  clickable?: boolean
}>(), {
  color: 'primary',
  clickable: false,
})

defineEmits<{ click: [] }>()

const formattedValue = computed(() => {
  if (typeof props.value === 'number') {
    return props.value === -1 ? '∞' : props.value.toLocaleString('pt-BR')
  }
  return props.value
})
// Tooltip explicativo do glifo ∞ — convenção interna p/ saldo ilimitado (superadmin).
// Sem isso, operador de suporte podia disparar achando "custo zero" (P0-25).
const valueTitle = computed(() =>
  typeof props.value === 'number' && props.value === -1
    ? 'Saldo ilimitado (perfil administrativo). Esta conta não consome créditos.'
    : ''
)
</script>

<style scoped>
.kpi-card {
  background: var(--bc-glass-bg, rgba(255,255,255,0.06));
  backdrop-filter: var(--bc-glass-blur, blur(16px));
  -webkit-backdrop-filter: var(--bc-glass-blur, blur(16px));
  border: none;
  border-radius: 14px;
  padding: 1.25rem;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  overflow: hidden;
}
.kpi-card:hover {
  box-shadow: 0 4px 24px rgba(8, 12, 37, 0.10);
}
.kpi-clickable { cursor: pointer; }
.kpi-clickable:hover { transform: translateY(-2px); }

.kpi-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
}
.kpi-label {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--bc-text-muted, #6c7293);
}
.kpi-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
}
.kpi-icon-primary { background: rgba(0,100,255,0.14); color: var(--bc-primary); }
.kpi-icon-success { background: rgba(16,185,129,0.14); color: #10b981; }
.kpi-icon-danger  { background: rgba(239,68,68,0.14); color: #ef4444; }
.kpi-icon-warning { background: rgba(245,158,11,0.14); color: #f59e0b; }
.kpi-icon-info    { background: rgba(6,182,212,0.14); color: #06b6d4; }

.kpi-value {
  display: flex;
  align-items: baseline;
  gap: 0.35rem;
}
.kpi-number {
  font-family: 'Manrope', sans-serif;
  font-size: 2.2rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  color: var(--bc-text, #1a1d2e);
  line-height: 1;
}
.kpi-suffix {
  font-size: 0.82rem;
  color: var(--bc-text-muted);
  font-weight: 500;
}
.kpi-subtitle {
  margin-top: 0.5rem;
  font-size: 0.78rem;
  color: var(--bc-text-muted);
}
.kpi-extra {
  margin-top: 0.75rem;
}
</style>
