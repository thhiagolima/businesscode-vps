<template>
  <div
    class="modal modal-blur fade show d-block"
    style="background:rgba(0,0,0,0.5)"
    tabindex="-1"
    role="dialog"
    @click.self="$emit('close')"
  >
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content" style="border-radius:14px">
        <div class="modal-header">
          <h5 class="modal-title" style="font-weight:700">
            Editar preço — {{ serviceLabel(price.service) }}
          </h5>
          <button type="button" class="btn-close" aria-label="Fechar" @click="$emit('close')"></button>
        </div>
        <div class="modal-body">
          <div v-if="mode === 'global'" class="alert alert-warning mb-3" role="alert">
            <i class="ti ti-alert-triangle me-1"></i>
            Esta alteração é global e afeta APENAS novos envios.
          </div>
          <div v-else class="alert alert-info mb-3" role="alert">
            <i class="ti ti-info-circle me-1"></i>
            Override individual para este tenant. Custo permanece o global.
          </div>

          <div v-if="mode === 'global'" class="mb-3">
            <label class="form-label required">Custo (R$)</label>
            <input
              type="number"
              class="form-control"
              :class="{ 'is-invalid': errors.cost }"
              v-model.number="costBrl"
              step="0.0001"
              min="0"
              style="border-radius:10px"
            />
            <div class="form-hint" style="font-size:0.72rem">
              Aceita até 4 casas decimais (ex: 0,0605). Custos reais de fornecedores costumam ter precisão sub-centavo.
            </div>
            <div v-if="errors.cost" class="invalid-feedback">{{ errors.cost }}</div>
          </div>

          <div class="mb-3">
            <label class="form-label required">Venda (R$)</label>
            <input
              type="number"
              class="form-control"
              :class="{ 'is-invalid': errors.sale }"
              v-model.number="saleBrl"
              step="0.0001"
              min="0"
              style="border-radius:10px"
            />
            <div v-if="errors.sale" class="invalid-feedback">{{ errors.sale }}</div>
            <div v-if="mode === 'tenant'" class="form-hint" style="font-size:0.75rem">
              Default global: R$ {{ formatPrice(price.cost_micros, price.cost_cents) }} custo / R$ {{ formatPrice(price.sale_micros, price.sale_cents) }} venda
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label required">Razão da alteração</label>
            <textarea
              class="form-control"
              :class="{ 'is-invalid': errors.reason }"
              v-model="reason"
              rows="3"
              placeholder="Mínimo 5 caracteres. Será registrado no audit log."
              style="border-radius:10px"
            ></textarea>
            <div v-if="errors.reason" class="invalid-feedback">{{ errors.reason }}</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link" @click="$emit('close')">Cancelar</button>
          <button
            v-if="mode === 'tenant' && price.has_override"
            type="button"
            class="btn btn-outline-danger me-auto"
            :disabled="saving"
            @click="removeOverride"
          >
            <i class="ti ti-x me-1"></i>
            Remover override
          </button>
          <button
            type="button"
            class="btn btn-primary"
            :disabled="saving"
            @click="save"
          >
            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
            Salvar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useBillingStore } from '@/stores/billing'
import { useToast } from '@/composables/useToast'

interface PriceShape {
  service: string
  cost_cents: number
  sale_cents: number
  cost_micros?: number
  sale_micros?: number
  has_override?: boolean
}

const props = defineProps<{
  price: PriceShape
  mode?: 'global' | 'tenant'
  tenantId?: number
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'saved'): void
}>()

const mode = props.mode ?? 'global'

// Prefer micros (sub-cent precision) when initializing inputs.
// 1 micro = R$ 0,00001 → divide by 100000 to get reais.
function microsOrCentsToReais(micros: number | undefined, cents: number | undefined): number {
  const m = Number(micros ?? 0)
  return m > 0 ? m / 100000 : Number(cents ?? 0) / 100
}

const costBrl = ref<number>(microsOrCentsToReais(props.price.cost_micros, props.price.cost_cents))
const saleBrl = ref<number>(microsOrCentsToReais(props.price.sale_micros, props.price.sale_cents))
const reason = ref('')
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const store = useBillingStore()
const toast = useToast()

function formatPrice(micros: number | null | undefined, cents?: number | null): string {
  const reais = microsOrCentsToReais(micros ?? undefined, cents ?? undefined)
  const sign = reais < 0 ? '-' : ''
  const abs = Math.abs(reais).toFixed(4)
  const match = abs.match(/^(\d+)\.(\d{2})(\d{2})$/)
  if (!match) return sign + abs.replace('.', ',')
  const [, intPart, twoDec, extra] = match
  const trimmed = extra.replace(/0+$/, '')
  return sign + intPart + ',' + twoDec + trimmed
}

function serviceLabel(s: string): string {
  const map: Record<string, string> = {
    sms: 'SMS',
    voice: 'Voz (TTS)',
    email: 'Email',
    ai_generation: 'Geração IA',
    audio_tts: 'Áudio TTS',
  }
  return map[s] || s
}

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (mode === 'global') {
    if (!(costBrl.value >= 0) || isNaN(costBrl.value)) {
      errs.cost = 'Informe um custo válido (>= 0)'
    }
  }
  if (!(saleBrl.value >= 0) || isNaN(saleBrl.value)) {
    errs.sale = 'Informe um valor de venda válido (>= 0)'
  }
  if (!reason.value || reason.value.trim().length < 5) {
    errs.reason = 'A razão deve ter pelo menos 5 caracteres'
  }
  errors.value = errs
  return Object.keys(errs).length === 0
}

async function save() {
  if (!validate()) return
  saving.value = true
  try {
    if (mode === 'tenant' && props.tenantId) {
      await store.setTenantPrice(
        props.tenantId,
        props.price.service,
        Math.round(saleBrl.value * 100),
        reason.value.trim()
      )
    } else {
      // Send both cents (rounded mirror) and micros (true precision, source of truth).
      // Backend prefers micros and recalculates cents = round(micros/1000) to keep them in sync.
      const costMicros = Math.round(costBrl.value * 100000)
      const saleMicros = Math.round(saleBrl.value * 100000)
      await store.updatePrice(props.price.service, {
        cost_cents: Math.round(costMicros / 1000),
        sale_cents: Math.round(saleMicros / 1000),
        cost_micros: costMicros,
        sale_micros: saleMicros,
        reason: reason.value.trim(),
      })
    }
    toast.success('Preço atualizado')
    emit('saved')
  } catch (e: any) {
    const msg = e?.response?.data?.message || e?.message || 'Erro ao salvar'
    toast.error(msg)
  } finally {
    saving.value = false
  }
}

async function removeOverride() {
  if (!props.tenantId) return
  if (!reason.value || reason.value.trim().length < 5) {
    errors.value = { reason: 'Informe a razão (>= 5 caracteres) para remover o override' }
    return
  }
  saving.value = true
  try {
    await store.setTenantPrice(props.tenantId, props.price.service, null, reason.value.trim())
    toast.success('Override removido — voltou ao preço padrão')
    emit('saved')
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao remover override')
  } finally {
    saving.value = false
  }
}
</script>
