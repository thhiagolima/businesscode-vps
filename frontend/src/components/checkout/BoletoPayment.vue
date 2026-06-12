<template>
  <div>
    <div v-if="!boletoData">
      <div class="mb-3">
        <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--bc-text-muted);display:block;margin-bottom:.4rem">CPF ou CNPJ</label>
        <input
          v-model="document"
          type="text"
          class="form-control"
          placeholder="000.000.000-00"
          style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:.75rem 1rem"
        />
      </div>
      <button
        class="btn btn-primary w-100"
        style="border-radius:10px;padding:.75rem;font-weight:700"
        :disabled="!document.trim() || generating"
        @click="$emit('generate', document)"
      >
        <span v-if="generating" class="spinner-border spinner-border-sm me-2"></span>
        {{ generating ? 'Gerando...' : 'Gerar Boleto' }}
      </button>
    </div>

    <div v-else class="text-center">
      <div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.15);border-radius:12px;padding:1.5rem;margin-bottom:1rem">
        <i class="ti ti-file-invoice" style="font-size:2rem;color:#10b981;display:block;margin-bottom:.5rem"></i>
        <div style="font-weight:700;margin-bottom:.25rem">Boleto gerado!</div>
        <div style="font-size:.82rem;color:var(--bc-text-muted)">O pagamento pode levar 1-3 dias úteis para ser confirmado.</div>
      </div>

      <div v-if="boletoData.boleto_barcode" class="mb-3">
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--bc-text-muted);display:block;margin-bottom:.4rem">Linha digitável</label>
        <div class="d-flex gap-2">
          <input
            :value="boletoData.boleto_barcode"
            readonly
            class="form-control form-control-sm"
            style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;font-size:.72rem;font-family:'JetBrains Mono',monospace"
          />
          <button class="btn btn-sm btn-outline-primary" style="border-radius:8px;white-space:nowrap" @click="copyBarcode">
            <i class="ti ti-copy me-1"></i>{{ copied ? 'Copiado!' : 'Copiar' }}
          </button>
        </div>
      </div>

      <a
        v-if="boletoData.boleto_url"
        :href="boletoData.boleto_url"
        target="_blank"
        class="btn btn-outline-primary w-100"
        style="border-radius:10px;padding:.7rem"
      >
        <i class="ti ti-external-link me-1"></i>Abrir Boleto (PDF)
      </a>

      <div v-if="polling" class="mt-3" style="font-size:.85rem;color:var(--bc-text-muted)">
        <span class="spinner-border spinner-border-sm me-2"></span>Aguardando confirmação do pagamento...
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onUnmounted } from 'vue'
import { useCheckoutStore, type BoletoData } from '@/stores/checkout'

const props = defineProps<{
  boletoData: BoletoData | null
  generating: boolean
}>()

const emit = defineEmits<{ generate: [document: string]; approved: [] }>()

const store = useCheckoutStore()
const document = ref('')
const copied = ref(false)
const polling = ref(false)

function copyBarcode() {
  const input = (globalThis.document as Document).querySelector('.form-control-sm[readonly]') as HTMLInputElement
  if (input) navigator.clipboard.writeText(input.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}

function startPolling() {
  if (!props.boletoData?.payment_id) return
  polling.value = true
  store.pollPaymentStatus(props.boletoData.payment_id, () => {
    polling.value = false
    emit('approved')
  })
}

// Quando o boleto é gerado, inicia o polling — igual ao PIX. O boleto pode levar
// dias para compensar, mas se o pagamento for confirmado com a página aberta
// (ex.: pagamento via internet banking), a tela reflete na hora.
watch(() => props.boletoData, (val) => {
  if (val) startPolling()
}, { immediate: true })

onUnmounted(() => {
  store.stopPolling()
})
</script>
