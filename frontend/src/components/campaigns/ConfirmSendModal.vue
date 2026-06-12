<template>
  <div class="modal modal-blur fade" :class="{ show: visible }" :style="{ display: visible ? 'block' : 'none' }" tabindex="-1">
    <div class="modal-dialog modal-md modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Confirmar disparo</h5>
          <button type="button" class="btn-close" @click="$emit('cancelled')"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning">
            <i class="ti ti-alert-triangle me-2"></i>
            Esta ação não pode ser desfeita. As mensagens serão enviadas imediatamente.
          </div>
          <table class="table table-borderless table-sm mb-0">
            <tbody>
              <tr>
                <td class="text-muted" style="width:140px">Campanha</td>
                <td class="fw-bold">{{ campaign.name }}</td>
              </tr>
              <tr>
                <td class="text-muted">Canal</td>
                <td><span :class="['badge', channelBadge]">{{ channelLabel }}</span></td>
              </tr>
              <tr>
                <td class="text-muted">Lista</td>
                <td class="fw-bold">{{ campaign.contact_list?.name ?? '—' }}</td>
              </tr>
              <tr>
                <td class="text-muted">Destinatários</td>
                <td class="fw-bold">{{ campaign.estimated_contacts?.toLocaleString('pt-BR') ?? 0 }}</td>
              </tr>
              <tr>
                <td class="text-muted">Custo estimado</td>
                <td class="fw-bold text-primary">
                  <span v-if="pricingLoading"><span class="spinner-border spinner-border-sm"></span></span>
                  <span v-else-if="pricingError" class="text-danger">— (falha ao consultar tarifa)</span>
                  <span v-else>{{ brl(estimatedCost) }}</span>
                </td>
              </tr>
              <tr>
                <td class="text-muted">Saldo após envio</td>
                <td :class="['fw-bold', remainingBalance >= 0 ? 'text-success' : 'text-danger']">
                  <span v-if="pricingLoading || pricingError">—</span>
                  <span v-else>{{ brl(remainingBalance) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="remainingBalance < 0" class="alert alert-danger mt-2 mb-0">
            <i class="ti ti-alert-circle me-1"></i>
            Saldo insuficiente. Faltam {{ brl(Math.abs(remainingBalance)) }}.
          </div>
          <div v-if="contentPreview" class="bg-light rounded p-2 mt-3" style="font-size:0.8rem;max-height:80px;overflow:hidden">
            <strong>Preview:</strong> {{ contentPreview }}
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-link link-secondary" @click="$emit('cancelled')">Cancelar</button>
          <button class="btn btn-danger" @click="$emit('confirmed')"
            :disabled="isSending || pricingLoading || pricingError || remainingBalance < 0">
            <span v-if="isSending" class="spinner-border spinner-border-sm me-2"></span>
            <i v-else class="ti ti-send me-1"></i>
            Confirmar Disparo
          </button>
        </div>
      </div>
    </div>
  </div>
  <div v-if="visible" class="modal-backdrop fade show"></div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { brl } from '@/utils/currency'
import { useApi } from '@/composables/useApi'

const props = defineProps<{
  visible: boolean
  campaign: {
    id: number
    name: string
    type: 'sms' | 'voice' | 'email' | 'whatsapp'
    content?: string
    subject?: string
    estimated_contacts: number
    contact_list?: { name: string }
  }
  /** Saldo do tenant em centavos (inclui o limite de crédito, se houver). */
  creditsBalance: number
  isSending?: boolean
}>()

defineEmits<{
  confirmed: []
  cancelled: []
}>()

// Preço real do canal vem da API `/account/pricing` (única fonte de verdade — P0-03).
// NUNCA hardcoded para não divergir do backend.
const { get } = useApi()
const unitSaleCents = ref<number | null>(null)
const pricingLoading = ref(false)
const pricingError = ref(false)

async function loadPricing() {
  pricingLoading.value = true
  pricingError.value = false
  try {
    const res = await get<any>('/account/pricing')
    const list = Array.isArray(res) ? res : (res?.data ?? [])
    const match = list.find((p: any) => p.service === props.campaign.type)
    if (!match) {
      pricingError.value = true
      unitSaleCents.value = null
    } else {
      unitSaleCents.value = Number(match.sale_cents)
    }
  } catch {
    pricingError.value = true
    unitSaleCents.value = null
  } finally {
    pricingLoading.value = false
  }
}

watch(() => props.visible, (v) => {
  if (v) loadPricing()
})

const channelLabel = computed(() => ({ sms: 'SMS', voice: 'Voz', email: 'Email', whatsapp: 'WhatsApp' }[props.campaign.type] ?? props.campaign.type))
const channelBadge = computed(() => ({ sms: 'bg-green', voice: 'bg-pink', email: 'bg-blue', whatsapp: 'bg-green' }[props.campaign.type] ?? 'bg-secondary'))
const estimatedCost = computed(() => {
  const unit = unitSaleCents.value ?? 0
  return (props.campaign.estimated_contacts ?? 0) * unit
})
const remainingBalance = computed(() => props.creditsBalance - estimatedCost.value)
const contentPreview = computed(() => {
  const text = props.campaign.content ?? props.campaign.subject ?? ''
  return text.length > 200 ? text.slice(0, 200) + '...' : text
})
</script>
