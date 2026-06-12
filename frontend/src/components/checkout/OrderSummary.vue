<template>
  <div class="bc-order-summary">
    <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:1.5rem">Resumo do Pedido</h3>

    <div style="border-bottom:1px solid rgba(255,255,255,0.06);padding-bottom:1.25rem;margin-bottom:1.25rem">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <div style="font-weight:700;font-size:1.05rem">{{ title }}</div>
          <div style="font-size:.82rem;color:var(--bc-text-muted)">{{ subtitle }}</div>
        </div>
        <span style="font-weight:800;font-size:1.1rem;font-family:'JetBrains Mono',monospace">R${{ formatPrice(basePrice) }}</span>
      </div>

      <div>
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--bc-text-muted);display:block;margin-bottom:.4rem">Cupom de desconto</label>
        <div class="d-flex gap-2">
          <input
            v-model="couponInput"
            type="text"
            class="form-control form-control-sm"
            placeholder="Digite o código"
            :disabled="!!appliedCoupon"
            style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;font-size:.85rem"
          />
          <button
            v-if="!appliedCoupon"
            class="btn btn-sm btn-outline-secondary"
            style="border-radius:8px;white-space:nowrap"
            :disabled="!couponInput.trim() || validating"
            @click="applyCoupon"
          >
            {{ validating ? '...' : 'Aplicar' }}
          </button>
          <button
            v-else
            class="btn btn-sm btn-outline-danger"
            style="border-radius:8px;white-space:nowrap"
            @click="removeCoupon"
          >
            Remover
          </button>
        </div>
        <div v-if="couponError" style="color:#ef4444;font-size:.78rem;margin-top:.3rem">{{ couponError }}</div>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.25rem">
      <div class="d-flex justify-content-between" style="font-size:.9rem;color:var(--bc-text-muted)">
        <span>Subtotal</span>
        <span>R${{ formatPrice(basePrice) }}</span>
      </div>
      <div v-if="discount > 0" class="d-flex justify-content-between" style="font-size:.9rem;color:#10b981">
        <span>Desconto</span>
        <span>-R${{ formatPrice(discount) }}</span>
      </div>
      <div class="d-flex justify-content-between align-items-center" style="padding-top:.6rem;border-top:1px solid rgba(255,255,255,0.06)">
        <span style="font-weight:700;font-size:1rem">Total</span>
        <span style="font-weight:800;font-size:1.6rem;font-family:'JetBrains Mono',monospace;letter-spacing:-.02em">R${{ formatPrice(total) }}</span>
      </div>
    </div>

    <button
      class="btn btn-primary w-100"
      style="border-radius:10px;padding:.85rem;font-weight:700;font-size:.95rem"
      :disabled="processing"
      @click="$emit('submit')"
    >
      <i v-if="!processing" class="ti ti-lock me-2" style="font-size:1rem"></i>
      <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
      {{ processing ? 'Processando...' : 'Finalizar Compra' }}
    </button>

    <p style="text-align:center;font-size:.72rem;color:var(--bc-text-muted);margin-top:.8rem;padding:0 .5rem">
      Ao clicar em "Finalizar Compra", você concorda com nossos
      <a href="/terms" style="color:#0064ff">Termos de Uso</a> e
      <a href="/privacy" style="color:#0064ff">Política de Privacidade</a>.
    </p>

    <div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-top:1.5rem;opacity:.4">
      <div style="display:flex;align-items:center;gap:.3rem">
        <i class="ti ti-shield-check" style="font-size:.85rem"></i>
        <span style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em">SSL</span>
      </div>
      <div style="width:1px;height:14px;background:rgba(255,255,255,0.15)"></div>
      <div style="display:flex;align-items:center;gap:.3rem">
        <i class="ti ti-credit-card" style="font-size:.85rem"></i>
        <span style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em">Mercado Pago</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useCheckoutStore } from '@/stores/checkout'

const props = defineProps<{
  title: string
  subtitle: string
  basePrice: number
  planId?: number
  processing: boolean
}>()

defineEmits<{ submit: [] }>()

const store = useCheckoutStore()
const couponInput = ref('')
const validating = ref(false)
const couponError = ref('')

const appliedCoupon = computed(() => store.coupon)
const discount = computed(() => store.calculatedDiscount)
const total = computed(() => Math.max(0, props.basePrice - discount.value))

async function applyCoupon() {
  couponError.value = ''
  validating.value = true
  const valid = await store.validateCoupon(couponInput.value, props.planId)
  validating.value = false
  if (!valid) {
    couponError.value = store.errorMessage || 'Cupom inválido.'
  }
}

function removeCoupon() {
  store.clearCoupon()
  couponInput.value = ''
  couponError.value = ''
}

function formatPrice(v: number) {
  return v.toFixed(2).replace('.', ',')
}
</script>

<style scoped>
.bc-order-summary {
  background: var(--bc-gray, rgba(22, 27, 69, 0.6));
  border: 1px solid rgba(255,255,255,0.05);
  border-radius: 16px;
  padding: 28px 24px;
  position: sticky;
  top: 100px;
}
</style>
