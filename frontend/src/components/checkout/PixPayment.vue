<template>
  <div class="text-center">
    <div v-if="pixData" style="background:white;border-radius:12px;padding:20px;display:inline-block;margin-bottom:1rem">
      <img :src="'data:image/png;base64,' + pixData.pix_qr_code_base64" alt="QR Code PIX" style="width:200px;height:200px" />
    </div>

    <div v-if="pixData?.pix_qr_code" class="mb-3">
      <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--bc-text-muted);display:block;margin-bottom:.4rem">Código PIX (copia e cola)</label>
      <div class="d-flex gap-2">
        <input
          :value="pixData.pix_qr_code"
          readonly
          class="form-control form-control-sm"
          style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;font-size:.75rem;font-family:'JetBrains Mono',monospace"
        />
        <button class="btn btn-sm btn-outline-primary" style="border-radius:8px;white-space:nowrap" @click="copyCode">
          <i class="ti ti-copy me-1"></i>{{ copied ? 'Copiado!' : 'Copiar' }}
        </button>
      </div>
    </div>

    <div v-if="remaining > 0" style="font-size:.85rem;color:var(--bc-text-muted)">
      <i class="ti ti-clock me-1"></i>Expira em {{ formatTime(remaining) }}
    </div>
    <div v-else-if="pixData" style="font-size:.85rem;color:#ef4444">
      <i class="ti ti-alert-circle me-1"></i>QR Code expirado. Gere um novo.
    </div>

    <div v-if="polling" class="mt-3" style="font-size:.85rem;color:var(--bc-text-muted)">
      <span class="spinner-border spinner-border-sm me-2"></span>Aguardando pagamento...
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onUnmounted } from 'vue'
import { useCheckoutStore, type PixData } from '@/stores/checkout'

const props = defineProps<{ pixData: PixData | null }>()
const emit = defineEmits<{ approved: [] }>()

const store = useCheckoutStore()
const copied = ref(false)
const remaining = ref(0)
const polling = ref(false)
let timer: ReturnType<typeof setInterval> | null = null

function copyCode() {
  if (!props.pixData?.pix_qr_code) return
  navigator.clipboard.writeText(props.pixData.pix_qr_code)
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}

function formatTime(seconds: number) {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${s.toString().padStart(2, '0')}`
}

function startTimer() {
  if (!props.pixData?.pix_expiration) return
  const expiration = new Date(props.pixData.pix_expiration).getTime()
  timer = setInterval(() => {
    const now = Date.now()
    remaining.value = Math.max(0, Math.floor((expiration - now) / 1000))
    if (remaining.value <= 0 && timer) {
      clearInterval(timer)
      store.stopPolling()
      polling.value = false
    }
  }, 1000)
}

function startPolling() {
  if (!props.pixData?.payment_id) return
  polling.value = true
  store.pollPaymentStatus(props.pixData.payment_id, () => {
    polling.value = false
    emit('approved')
  })
}

watch(() => props.pixData, (val) => {
  if (val) {
    startTimer()
    startPolling()
  }
}, { immediate: true })

onUnmounted(() => {
  if (timer) clearInterval(timer)
  store.stopPolling()
})
</script>
