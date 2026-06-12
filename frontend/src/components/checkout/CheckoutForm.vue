<template>
  <div>
    <div class="d-flex gap-2 mb-4">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        class="btn flex-fill"
        :class="activeTab === tab.id ? 'btn-primary' : 'btn-outline-secondary'"
        style="border-radius:10px;padding:.6rem;font-size:.85rem;font-weight:600"
        @click="activeTab = tab.id"
      >
        <i :class="tab.icon" class="me-1"></i>{{ tab.label }}
      </button>
    </div>

    <div v-if="activeTab === 'credit_card'" class="bc-checkout-section">
      <div class="d-flex align-items-center gap-2 mb-4">
        <i class="ti ti-credit-card" style="color:#0064ff;font-size:1.2rem"></i>
        <h3 style="font-size:1.05rem;font-weight:700;margin:0">Cartão de Crédito</h3>
      </div>
      <div class="row g-3">
        <div class="col-12">
          <label class="bc-label">Número do cartão</label>
          <input v-model="card.number" type="text" class="form-control bc-input" placeholder="0000 0000 0000 0000" maxlength="19" @input="formatCardNumber" />
        </div>
        <div class="col-6">
          <label class="bc-label">Validade</label>
          <input v-model="card.expiry" type="text" class="form-control bc-input" placeholder="MM/AA" maxlength="5" @input="formatExpiry" />
        </div>
        <div class="col-6">
          <label class="bc-label">CVC</label>
          <input v-model="card.cvc" type="text" class="form-control bc-input" placeholder="123" maxlength="4" />
        </div>
        <div class="col-12">
          <label class="bc-label">Nome no cartão</label>
          <input v-model="card.name" type="text" class="form-control bc-input" placeholder="Nome como está no cartão" />
        </div>
        <div class="col-12">
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" v-model="saveCard" id="save-card-for-billing" />
            <label class="form-check-label" for="save-card-for-billing" style="font-size:.85rem">
              Salvar cartão para débito mensal automático do consumo excedente.
            </label>
          </div>
        </div>
      </div>
    </div>

    <div v-if="activeTab === 'pix'" class="bc-checkout-section">
      <div class="d-flex align-items-center gap-2 mb-4">
        <i class="ti ti-qrcode" style="color:#0064ff;font-size:1.2rem"></i>
        <h3 style="font-size:1.05rem;font-weight:700;margin:0">PIX</h3>
      </div>
      <div v-if="!pixData" style="text-align:center;padding:1rem 0">
        <p style="font-size:.9rem;color:var(--bc-text-muted);margin-bottom:1rem">
          Clique em "Finalizar Compra" para gerar o QR Code PIX.
        </p>
        <p style="font-size:.78rem;color:var(--bc-text-muted)">
          <i class="ti ti-clock me-1"></i>Pagamento confirmado em segundos
        </p>
      </div>
      <PixPayment v-else :pix-data="pixData" @approved="$emit('success')" />
    </div>

    <div v-if="activeTab === 'boleto'" class="bc-checkout-section">
      <div class="d-flex align-items-center gap-2 mb-4">
        <i class="ti ti-file-invoice" style="color:#0064ff;font-size:1.2rem"></i>
        <h3 style="font-size:1.05rem;font-weight:700;margin:0">Boleto Bancário</h3>
      </div>
      <BoletoPayment
        :boleto-data="boletoData"
        :generating="store.paymentStatus === 'processing'"
        @generate="(doc: string) => $emit('boleto-generate', doc)"
        @approved="$emit('success')"
      />
    </div>

    <div v-if="store.errorMessage" class="mt-3" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.15);border-radius:10px;padding:.75rem 1rem">
      <div style="font-size:.85rem;color:#ef4444">
        <i class="ti ti-alert-circle me-1"></i>{{ store.errorMessage }}
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useCheckoutStore, type PixData, type BoletoData } from '@/stores/checkout'
import PixPayment from './PixPayment.vue'
import BoletoPayment from './BoletoPayment.vue'

defineProps<{
  pixData: PixData | null
  boletoData: BoletoData | null
}>()

defineEmits<{
  success: []
  'boleto-generate': [document: string]
}>()

const store = useCheckoutStore()
const activeTab = ref<'credit_card' | 'pix' | 'boleto'>('credit_card')

const card = ref({
  number: '',
  expiry: '',
  cvc: '',
  name: '',
})

// Default checked: persist the card with MP for future monthly overage debits.
const saveCard = ref<boolean>(true)

const tabs = [
  { id: 'credit_card' as const, label: 'Cartão', icon: 'ti ti-credit-card' },
  { id: 'pix' as const, label: 'PIX', icon: 'ti ti-qrcode' },
  { id: 'boleto' as const, label: 'Boleto', icon: 'ti ti-file-invoice' },
]

function formatCardNumber() {
  card.value.number = card.value.number
    .replace(/\D/g, '')
    .replace(/(\d{4})(?=\d)/g, '$1 ')
    .slice(0, 19)
}

function formatExpiry() {
  let v = card.value.expiry.replace(/\D/g, '')
  if (v.length >= 2) v = v.slice(0, 2) + '/' + v.slice(2)
  card.value.expiry = v.slice(0, 5)
}

function getActiveTab() {
  return activeTab.value
}

function getCardData() {
  return card.value
}

function getSaveCard(): boolean {
  return saveCard.value
}

defineExpose({ getActiveTab, getCardData, getSaveCard })
</script>

<style scoped>
.bc-checkout-section {
  background: var(--bc-gray, rgba(22, 27, 69, 0.4));
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px;
  padding: 24px;
}
.bc-label {
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--bc-text-muted);
  display: block;
  margin-bottom: .35rem;
}
.bc-input {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 10px;
  padding: .75rem 1rem;
  color: var(--bc-text);
  font-size: .9rem;
}
.bc-input:focus {
  border-color: rgba(0,100,255,0.3);
  box-shadow: 0 0 0 3px rgba(0,100,255,0.08);
}
</style>
