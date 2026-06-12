<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-.03em;margin:0">Meu Saldo</h2>
        <span style="font-size:.85rem;color:var(--bc-text-muted)">Acompanhe seu saldo, limite de crédito e próxima cobrança</span>
      </div>
    </div>

    <!-- Billing status banners -->
    <div
      v-if="billingStatus === 'grace'"
      class="mb-4 p-3 d-flex align-items-center gap-3"
      style="background:rgba(255,165,0,0.08);border:1px solid rgba(255,165,0,0.25);border-radius:12px"
    >
      <i class="ti ti-alert-triangle" style="color:#ff9800;font-size:1.4rem"></i>
      <div class="flex-grow-1">
        <div style="font-weight:700;color:#ff9800">Pagamento pendente</div>
        <div style="font-size:.85rem;color:var(--bc-text-muted)">Atualize seu cartão para evitar a suspensão da conta.</div>
      </div>
      <button class="btn btn-warning btn-sm" @click="goToPlans">Atualizar cartão</button>
    </div>

    <div
      v-if="billingStatus === 'suspended' || billingStatus === 'blocked'"
      class="mb-4 p-3 d-flex align-items-center gap-3"
      style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);border-radius:12px"
    >
      <i class="ti ti-lock" style="color:#ef4444;font-size:1.4rem"></i>
      <div class="flex-grow-1">
        <div style="font-weight:700;color:#ef4444">Conta bloqueada</div>
        <div style="font-size:.85rem;color:var(--bc-text-muted)">Entre em contato com o suporte para regularizar.</div>
      </div>
      <a v-if="supportWhatsAppUrl" :href="supportWhatsAppUrl" target="_blank" rel="noopener" class="btn btn-danger btn-sm">
        <i class="ti ti-brand-whatsapp me-1"></i>Falar com suporte
      </a>
      <span v-else class="text-muted small">
        Suporte: <span v-if="supportEmail">{{ supportEmail }}</span><span v-else>contate o administrador</span>
      </span>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-md-4">
        <div style="background:var(--bc-gray);border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:20px">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i class="ti ti-wallet" style="color:#0064ff;font-size:1.1rem"></i>
            <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--bc-text-muted)">Saldo atual</span>
          </div>
          <div :style="{
            fontWeight: 800,
            fontSize: '1.5rem',
            fontFamily: '\'JetBrains Mono\', monospace',
            color: balanceCents < 0 ? '#ef4444' : 'var(--bc-text)'
          }">{{ brl(balanceCents) }}</div>
        </div>
      </div>

      <div v-if="creditLimitCents > 0" class="col-12 col-md-4">
        <div style="background:var(--bc-gray);border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:20px">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i class="ti ti-credit-card" style="color:#0064ff;font-size:1.1rem"></i>
            <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--bc-text-muted)">Limite de crédito</span>
          </div>
          <div style="font-weight:800;font-size:1.5rem;font-family:'JetBrains Mono',monospace">{{ brl(creditLimitCents) }}</div>
          <div v-if="balanceCents < 0" style="font-size:.75rem;color:var(--bc-text-muted);margin-top:.25rem">
            Usado: {{ creditUsagePct }}%
          </div>
        </div>
      </div>

      <div class="col-12 col-md-4">
        <div style="background:var(--bc-gray);border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:20px">
          <div class="d-flex align-items-center gap-2 mb-2">
            <i class="ti ti-calendar" style="color:#0064ff;font-size:1.1rem"></i>
            <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--bc-text-muted)">Próxima cobrança</span>
          </div>
          <div style="font-weight:800;font-size:1.1rem;font-family:'JetBrains Mono',monospace">{{ nextBillingLabel }}</div>
          <div v-if="estimatedDueCents > 0" style="font-size:.85rem;color:var(--bc-text-muted);margin-top:.25rem">
            Valor estimado: <strong style="color:var(--bc-text)">{{ brl(estimatedDueCents) }}</strong>
          </div>
        </div>
      </div>
    </div>

    <!-- Top-up balance card -->
    <div v-if="configLoading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

    <div v-else class="row g-4">
      <div class="col-12 col-lg-7">
        <div class="mb-4" style="background:var(--bc-gray);border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:24px">
          <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--bc-text-muted);display:block;margin-bottom:.5rem">Recarregar saldo</label>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span style="font-weight:700;font-family:'JetBrains Mono',monospace;color:var(--bc-text-muted)">R$</span>
            <input
              :value="topupAmountFormatted"
              @input="onTopupInput"
              @blur="onTopupBlur"
              type="text"
              inputmode="numeric"
              autocomplete="off"
              class="form-control"
              aria-label="Valor da recarga em reais"
              style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:.75rem 1rem;font-size:1.2rem;font-weight:700;font-family:'JetBrains Mono',monospace"
            />
          </div>
          <div v-if="topupAmount < minPurchase" style="font-size:.78rem;color:#ef4444">Mínimo de R$ {{ minPurchase.toFixed(2).replace('.', ',') }}</div>
          <!-- Sugestões clicáveis para evitar digitação em mobile (P0-27). -->
          <div class="d-flex gap-2 mt-2 flex-wrap">
            <button v-for="v in [50, 100, 200, 500]" :key="v" type="button"
              class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.78rem"
              @click="topupAmount = v">R$ {{ v }}</button>
          </div>
          <div style="font-size:.85rem;color:var(--bc-text-muted);margin-top:.5rem">
            Pague em PIX, boleto ou cartão. O valor é adicionado integralmente ao seu saldo.
          </div>
        </div>

        <CheckoutForm
          ref="checkoutFormRef"
          :pix-data="store.pixData"
          :boleto-data="store.boletoData"
          @success="onPaymentSuccess"
          @boleto-generate="onBoletoGenerate"
        />
      </div>

      <div class="col-12 col-lg-5">
        <OrderSummary
          title="Recarga de Saldo"
          :subtitle="brl(topupAmount * 100)"
          :base-price="topupAmount"
          :processing="store.paymentStatus === 'processing'"
          @submit="handleSubmit"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCheckoutStore } from '@/stores/checkout'
import { useAuthStore } from '@/stores/auth'
import { brl } from '@/utils/currency'
import CheckoutForm from '@/components/checkout/CheckoutForm.vue'
import OrderSummary from '@/components/checkout/OrderSummary.vue'

const router = useRouter()
const store = useCheckoutStore()
const auth = useAuthStore()

const checkoutFormRef = ref<InstanceType<typeof CheckoutForm> | null>(null)
const topupAmount = ref(50)
const configLoading = ref(true)

// Máscara monetária BR (P0-27): em mobile pt-BR sem ponto era fácil digitar "999"
// e o input numérico aceitar como R$ 999 (na verdade R$ 9,99 era esperado).
// Tratamos a entrada como CENTAVOS (último 2 dígitos = decimal) e exibimos formatado.
const topupAmountFormatted = computed(() =>
  topupAmount.value.toFixed(2).replace('.', ',')
)
function onTopupInput(e: Event) {
  const input = e.target as HTMLInputElement
  const digitsOnly = input.value.replace(/\D/g, '')
  const cents = parseInt(digitsOnly || '0', 10)
  topupAmount.value = Math.max(0, cents / 100)
}
function onTopupBlur() {
  if (topupAmount.value < minPurchase.value) {
    // Não auto-corrige (deixa o erro visível) — apenas evita NaN/negativo.
    topupAmount.value = Math.max(0, topupAmount.value)
  }
}

// Tenant fields (safe access with fallbacks)
const tenant = computed<any>(() => auth.user?.tenant ?? null)
const balanceCents = computed<number>(() => tenant.value?.balance_cents ?? 0)
const creditLimitCents = computed<number>(() => tenant.value?.credit_limit_cents ?? 0)
const billingStatus = computed<string>(() => tenant.value?.billing_status ?? 'active')
const creditUsagePct = computed<number>(() => {
  if (creditLimitCents.value <= 0 || balanceCents.value >= 0) return 0
  return Math.min(100, Math.round((Math.abs(balanceCents.value) / creditLimitCents.value) * 100))
})

const nextBillingLabel = computed<string>(() => {
  const day = tenant.value?.billing_cycle_day
  if (!day) return '—'
  const now = new Date()
  // If we've already passed this day in the current month, next billing is next month.
  const target = new Date(now.getFullYear(), now.getMonth(), day)
  if (target.getTime() <= now.getTime()) {
    target.setMonth(target.getMonth() + 1)
  }
  return target.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
})

// If balance is negative, the user owes that amount on the next cycle.
const estimatedDueCents = computed<number>(() => (balanceCents.value < 0 ? Math.abs(balanceCents.value) : 0))

const minPurchase = computed<number>(() => {
  // store.config.credit_min_purchase historically expressed in credit units; we expose as R$ amount.
  // Default to R$ 10 if not configured.
  const raw = store.config?.credit_min_purchase
  if (typeof raw === 'number' && raw > 0 && raw < 1000) return raw
  return 10
})

const supportEmail = (import.meta as any).env?.VITE_SUPPORT_EMAIL ?? ''
const supportWhatsAppUrl = computed<string | null>(() => {
  // Sem env configurada NÃO mandamos o cliente para um número genérico/inválido.
  // Retornamos null e o template exibe o e-mail (ou orienta a falar com admin).
  const phone = (import.meta as any).env?.VITE_SUPPORT_WHATSAPP
  if (!phone) return null
  return `https://wa.me/${phone}?text=Preciso%20regularizar%20minha%20conta`
})

onMounted(async () => {
  store.reset()
  await store.fetchConfig()
  topupAmount.value = minPurchase.value
  configLoading.value = false
  // Refresh tenant data so balance/limit cards are accurate.
  if (auth.isAuthenticated) await auth.refreshUser()
})

onUnmounted(() => {
  store.stopPolling()
})

async function handleSubmit() {
  if (topupAmount.value < minPurchase.value || !checkoutFormRef.value) return
  const tab = checkoutFormRef.value.getActiveTab()
  const email = auth.user?.email ?? ''

  // Topup amount expressed in cents — the backend purchase endpoint historically
  // accepts a "credits_amount" parameter; we pass cents so 1 credit == R$ 0,01.
  const amountInCents = Math.round(topupAmount.value * 100)

  if (tab === 'credit_card') {
    const cardData = checkoutFormRef.value.getCardData()
    const mp = new (window as any).MercadoPago(store.config!.mp_public_key)
    try {
      const tokenResult = await mp.fields.createCardToken({
        cardNumber: cardData.number.replace(/\s/g, ''),
        cardholderName: cardData.name,
        cardExpirationMonth: cardData.expiry.split('/')[0],
        cardExpirationYear: '20' + cardData.expiry.split('/')[1],
        securityCode: cardData.cvc,
      })
      const saveCard = checkoutFormRef.value.getSaveCard?.() ?? false
      const res = await store.purchaseCredits(amountInCents, 'credit_card', email, tokenResult.id, undefined, saveCard)
      if (res?.status === 'approved') {
        onPaymentSuccess()
      } else if (res?.payment_id) {
        // Pagamento pendente: acompanha a confirmação antes de creditar/seguir.
        store.pollPaymentStatus(res.payment_id, onPaymentSuccess)
      }
    } catch {
      // error in store
    }
  } else if (tab === 'pix') {
    await store.purchaseCredits(amountInCents, 'pix', email)
  }
}

async function onBoletoGenerate(document: string) {
  const email = auth.user?.email ?? ''
  const amountInCents = Math.round(topupAmount.value * 100)
  await store.purchaseCredits(amountInCents, 'boleto', email, undefined, document)
}

function onPaymentSuccess() {
  auth.refreshUser()
  router.push({ path: '/checkout/thank-you', query: { plan: 'Recarga de Saldo' } })
}

function goToPlans() {
  router.push('/settings/plans')
}
</script>
