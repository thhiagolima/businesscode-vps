<template>
  <div>
    <div class="text-center mb-4">
      <h3 style="font-size:1.1rem;font-weight:700;color:var(--bc-text,#1a1d2e)">Revise antes de enviar</h3>
      <p style="font-size:0.85rem;color:var(--bc-text-muted,#6c7293)">Confira todos os detalhes da sua campanha</p>
    </div>

    <div class="row g-4">
      <!-- LEFT: Checklist -->
      <div class="col-12 col-lg-7">
        <!-- Item: Campanha -->
        <div class="bc-review-item">
          <div class="bc-review-icon" :class="name ? 'bc-ok' : 'bc-warn'">
            <i :class="name ? 'ti ti-circle-check' : 'ti ti-alert-circle'"></i>
          </div>
          <div class="bc-review-content">
            <div class="bc-review-label">Campanha</div>
            <div class="bc-review-value">{{ name || '—' }}</div>
          </div>
          <StatusBadge :label="channelLabel" :status="type" />
        </div>

        <!-- Conteúdo: apenas alerta se vazio, preview mostra o conteúdo -->
        <div v-if="!hasContent" class="bc-review-item">
          <div class="bc-review-icon bc-warn">
            <i class="ti ti-alert-circle"></i>
          </div>
          <div class="bc-review-content">
            <div class="bc-review-label">Conteúdo</div>
            <div class="bc-review-value" style="color:#d97706">Nenhum conteúdo definido</div>
          </div>
        </div>

        <!-- Item: Contatos -->
        <div class="bc-review-item">
          <div class="bc-review-icon" :class="totalContacts > 0 ? 'bc-ok' : 'bc-warn'">
            <i :class="totalContacts > 0 ? 'ti ti-circle-check' : 'ti ti-alert-circle'"></i>
          </div>
          <div class="bc-review-content">
            <div class="bc-review-label">Destinatários</div>
            <div class="d-flex align-items-center gap-2">
              <span class="bc-review-number">{{ totalContacts.toLocaleString('pt-BR') }}</span>
              <StatusBadge v-if="contactSource === 'list'" label="Lista salva" status="active" />
              <StatusBadge v-else-if="contactSource === 'file'" label="Arquivo CSV" status="active" />
              <StatusBadge v-else-if="contactSource === 'manual'" label="Digitados" status="trial" />
            </div>
            <div v-if="contactSource === 'list' && contactListLabel" style="font-size:0.78rem;color:var(--bc-text-muted);margin-top:2px">
              {{ contactListLabel }}
            </div>
            <!-- Preview números avulsos -->
            <div v-if="contactSource !== 'list' && adhocPhones.length > 0" class="bc-phones-preview mt-2">
              <span v-for="(p, i) in adhocPhones.slice(0, 3)" :key="i" class="bc-phone-chip">{{ p }}</span>
              <span v-if="adhocPhones.length > 3" class="bc-phone-chip bc-more">+{{ adhocPhones.length - 3 }}</span>
            </div>
          </div>
        </div>

        <!-- Item: Agendamento -->
        <div class="bc-review-item">
          <div class="bc-review-icon bc-ok">
            <i class="ti ti-circle-check"></i>
          </div>
          <div class="bc-review-content">
            <div class="bc-review-label">Envio</div>
            <div class="d-flex align-items-center gap-2">
              <i :class="scheduleAt ? 'ti ti-calendar-event text-primary' : 'ti ti-bolt'" style="font-size:1rem" :style="{ color: scheduleAt ? '' : '#0d9668' }"></i>
              <span class="bc-review-value">{{ scheduleAt ? formattedSchedule : 'Imediato após confirmar' }}</span>
            </div>
          </div>
        </div>

        <!-- Cost card -->
        <div class="bc-cost-card" :class="{ 'bc-cost-warn': insufficient }">
          <div class="row g-0 text-center">
            <div class="col-3 bc-cost-col">
              <div class="bc-cost-label">Contatos</div>
              <div class="bc-cost-value">{{ totalContacts.toLocaleString('pt-BR') }}</div>
            </div>
            <div class="col-3 bc-cost-col">
              <div class="bc-cost-label">Custo/envio</div>
              <div class="bc-cost-value">
                <template v-if="creditsPerSend !== null && creditsPerSend !== undefined">{{ brl(creditsPerSend) }}</template>
                <template v-else>—</template>
              </div>
            </div>
            <div class="col-3 bc-cost-col">
              <div class="bc-cost-label">Total</div>
              <div class="bc-cost-value bc-cost-primary">
                <template v-if="estimatedTotal !== null">{{ brl(estimatedTotal) }}</template>
                <template v-else>—</template>
              </div>
            </div>
            <div class="col-3 bc-cost-col">
              <div class="bc-cost-label">Saldo</div>
              <div class="bc-cost-value" :class="insufficient ? 'bc-cost-danger' : 'bc-cost-success'"
                   :title="currentBalance === -1 ? 'Saldo ilimitado (perfil administrativo). Esta conta não consome créditos.' : ''">
                <template v-if="currentBalance === -1">∞</template>
                <template v-else-if="currentBalance !== null && currentBalance !== undefined">{{ brl(currentBalance) }}</template>
                <template v-else>—</template>
              </div>
            </div>
          </div>
          <div v-if="insufficient && estimatedTotal !== null && currentBalance !== null && currentBalance !== undefined && currentBalance !== -1" class="bc-cost-alert">
            <i class="ti ti-alert-triangle me-1"></i>
            Faltam <strong>{{ brl(estimatedTotal - currentBalance) }}</strong>
          </div>
        </div>
      </div>

      <!-- RIGHT: Phone Preview -->
      <div class="col-12 col-lg-5">
        <div class="sticky-top" style="top:1rem">
          <div class="text-center mb-2" style="font-size:0.78rem;color:var(--bc-text-muted)">Preview</div>
          <PhonePreview :type="type" :content="content" :subject="subject" :audio-url="audioUrl" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import PhonePreview from '@/components/campaigns/PhonePreview.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { brl } from '@/utils/currency'

interface Props {
  name: string
  type: 'sms'|'voice'|'email'|'whatsapp'
  status?: string
  content?: string
  subject?: string | null
  audioUrl?: string
  contactSource?: 'list'|'file'|'manual'
  contactListLabel?: string
  contactsTotal?: number
  adhocPhones?: string[]
  scheduleAt?: string | null
  /** Preço unitário em centavos (vem de /account/pricing). null = ainda carregando / indisponível. */
  creditsPerSend: number | null
  /** Saldo em centavos. -1 = ilimitado (superadmin). null = não carregado. */
  currentBalance: number | null
}
const props = withDefaults(defineProps<Props>(), {
  contactSource: 'list',
  contactsTotal: 0,
  adhocPhones: () => [],
})

const emit = defineEmits<{
  'update:valid': [valid: boolean]
}>()

const channelLabel = computed(() => ({ sms: 'SMS', voice: 'Voz', email: 'Email', whatsapp: 'WhatsApp' }[props.type] ?? props.type))
const hasContent = computed(() => !!(props.content || props.audioUrl || props.subject))
const truncatedContent = computed(() => {
  const t = props.content ?? ''
  return t.length > 120 ? t.slice(0, 120) + '...' : t || '—'
})

const totalContacts = computed(() => {
  if (props.contactSource === 'list') return props.contactsTotal ?? 0
  return props.adhocPhones?.length ?? 0
})

const estimatedTotal = computed<number | null>(() => {
  if (props.creditsPerSend === null || props.creditsPerSend === undefined) return null
  return totalContacts.value * props.creditsPerSend
})
// Sem dados completos NÃO emitimos "insuficiente" — o passo simplesmente bloqueia avanço pela ausência de tarifa.
const insufficient = computed(() => {
  if (estimatedTotal.value === null) return false
  if (props.currentBalance === null || props.currentBalance === undefined) return false
  if (props.currentBalance === -1) return false
  return estimatedTotal.value > props.currentBalance
})

watch(insufficient, (val) => {
  emit('update:valid', !val)
}, { immediate: true })

const formattedSchedule = computed(() => {
  if (!props.scheduleAt) return ''
  try {
    return new Date(props.scheduleAt).toLocaleDateString('pt-BR', {
      weekday: 'short', day: '2-digit', month: 'short', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    })
  } catch { return props.scheduleAt ?? '' }
})
</script>

<style scoped>
/* Review checklist items */
.bc-review-item {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 1rem 0;
  border-bottom: 1px solid var(--bc-gray, #e4e8ef);
}
.bc-review-item:first-child { padding-top: 0; }
.bc-review-item:last-of-type { border-bottom: none; }

.bc-review-icon {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 1rem;
  margin-top: 2px;
}
.bc-review-icon.bc-ok { color: #0d9668; }
.bc-review-icon.bc-warn { color: #d97706; }

.bc-review-content { flex: 1; min-width: 0; }
.bc-review-label {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--bc-text-muted, #6c7293);
  margin-bottom: 2px;
}
.bc-review-value {
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--bc-text, #1a1d2e);
}
.bc-review-number {
  font-family: 'JetBrains Mono', monospace;
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--bc-text, #1a1d2e);
}
.bc-review-message {
  font-size: 0.85rem;
  color: var(--bc-text, #1a1d2e);
  line-height: 1.5;
  white-space: pre-wrap;
  padding: 0.5rem 0.75rem;
  background: rgba(0,100,255,0.02);
  border-left: 3px solid var(--bc-primary, #0064ff);
  border-radius: 0 6px 6px 0;
  margin-top: 4px;
}

/* Phone chips */
.bc-phones-preview { display: flex; flex-wrap: wrap; gap: 4px; }
.bc-phone-chip {
  display: inline-block;
  padding: 2px 8px;
  background: rgba(0,100,255,0.05);
  border-radius: 4px;
  font-size: 0.72rem;
  font-family: 'JetBrains Mono', monospace;
  color: var(--bc-text-muted, #6c7293);
}
.bc-phone-chip.bc-more { background: rgba(0,100,255,0.1); color: #0064ff; font-weight: 600; }

/* Cost card */
.bc-cost-card {
  margin-top: 1rem;
  padding: 1rem;
  border-radius: 12px;
  background: rgba(0,100,255,0.02);
  border: 1px solid rgba(0,100,255,0.1);
}
.bc-cost-card.bc-cost-warn {
  background: rgba(234,179,8,0.04);
  border-color: rgba(234,179,8,0.3);
}
.bc-cost-col { padding: 0.5rem 0; }
.bc-cost-label {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--bc-text-muted, #6c7293);
  margin-bottom: 4px;
}
.bc-cost-value {
  font-family: 'JetBrains Mono', monospace;
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--bc-text, #1a1d2e);
}
.bc-cost-primary { color: #0064ff; }
.bc-cost-success { color: #0d9668; }
.bc-cost-danger { color: #dc2626; }
.bc-cost-alert {
  margin-top: 0.75rem;
  padding: 0.5rem 0.75rem;
  background: rgba(234,179,8,0.08);
  border-radius: 6px;
  font-size: 0.8rem;
  color: #a16207;
  text-align: center;
}

/* Dark mode */
[data-bs-theme="dark"] .bc-review-message { background: rgba(0,100,255,0.06); }
[data-bs-theme="dark"] .bc-cost-card { background: rgba(0,100,255,0.05); }
[data-bs-theme="dark"] .bc-phone-chip { background: rgba(255,255,255,0.06); }
</style>
