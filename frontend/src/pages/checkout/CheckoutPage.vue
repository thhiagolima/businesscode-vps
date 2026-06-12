<template>
  <div>
    <div style="max-width:1100px;margin:0 auto" :style="isPublic ? 'padding: 2rem 1rem' : ''">
      <div class="mb-4">
        <h2 style="font-size:1.6rem;font-weight:800;letter-spacing:-.03em;margin-bottom:.3rem">Checkout</h2>
        <p style="font-size:.9rem;color:var(--bc-text-muted)">Complete sua assinatura para desbloquear o plano.</p>
      </div>

      <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

      <div v-else-if="plan" class="row g-4">
        <div class="col-12 col-lg-7">
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
            :title="plan.name"
            :subtitle="billingCycle === 'annual' ? 'Cobrança anual' : 'Cobrança mensal'"
            :base-price="basePrice"
            :plan-id="plan.id"
            :processing="store.paymentStatus === 'processing'"
            @submit="handleSubmit"
          />
        </div>
      </div>

      <div v-else class="text-center py-5">
        <p style="color:var(--bc-text-muted)">Plano não encontrado.</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi'
import { useCheckoutStore } from '@/stores/checkout'
import { useAuthStore } from '@/stores/auth'
import CheckoutForm from '@/components/checkout/CheckoutForm.vue'
import OrderSummary from '@/components/checkout/OrderSummary.vue'

const route = useRoute()
const router = useRouter()
const { get } = useApi()
const store = useCheckoutStore()
const auth = useAuthStore()

const plan = ref<any>(null)
const loading = ref(true)
const checkoutFormRef = ref<InstanceType<typeof CheckoutForm> | null>(null)

const isPublic = computed(() => route.path.startsWith('/plans'))
const billingCycle = computed<'monthly' | 'annual'>(() => (route.query.cycle === 'annual' ? 'annual' : 'monthly'))
const basePrice = computed(() => {
  if (!plan.value) return 0
  if (billingCycle.value === 'annual' && plan.value.price_annual) return Number(plan.value.price_annual)
  return Number(plan.value.price_monthly)
})

onMounted(async () => {
  store.reset()
  await store.fetchConfig()

  try {
    const slug = route.params.planSlug as string
    const plans = await get<any[]>('/plans')
    const list = Array.isArray(plans) ? plans : ((plans as any)?.data ?? [])
    plan.value = list.find((p: any) => p.slug === slug) ?? null
  } catch {
    plan.value = null
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  store.stopPolling()
})

async function handleSubmit() {
  if (!plan.value || !checkoutFormRef.value) return

  const tab = checkoutFormRef.value.getActiveTab()
  const email = auth.user?.email ?? ''

  if (tab === 'credit_card') {
    await handleCardPayment(email)
  } else if (tab === 'pix') {
    await handlePixPayment(email)
  }
}

async function handleCardPayment(email: string) {
  if (!plan.value || !checkoutFormRef.value || !store.config) return

  const cardData = checkoutFormRef.value.getCardData()

  const mp = new (window as any).MercadoPago(store.config.mp_public_key)
  try {
    const tokenResult = await mp.fields.createCardToken({
      cardNumber: cardData.number.replace(/\s/g, ''),
      cardholderName: cardData.name,
      cardExpirationMonth: cardData.expiry.split('/')[0],
      cardExpirationYear: '20' + cardData.expiry.split('/')[1],
      securityCode: cardData.cvc,
    })
    const couponCode = store.coupon?.code
    const saveCard = checkoutFormRef.value.getSaveCard?.() ?? true
    const sub = await store.createSubscription(plan.value.id, tokenResult.id, email, couponCode, billingCycle.value, saveCard)
    if (sub?.status === 'active') {
      onPaymentSuccess()
    } else {
      // Assinatura pendente: aguarda a confirmação do MercadoPago antes de
      // levar o usuário para a tela de obrigado.
      store.pollSubscriptionActive(onPaymentSuccess)
    }
  } catch {
    // error is already in store.errorMessage
  }
}

async function handlePixPayment(email: string) {
  if (!plan.value) return
  try {
    await store.createPixPayment(plan.value.id, email, undefined, billingCycle.value)
  } catch {
    // error in store
  }
}

async function onBoletoGenerate(document: string) {
  if (!plan.value) return
  const email = auth.user?.email ?? ''
  try {
    await store.createBoletoPayment(plan.value.id, email, document, undefined, billingCycle.value)
  } catch {
    // error in store
  }
}

function onPaymentSuccess() {
  auth.refreshUser()
  router.push({
    path: '/checkout/thank-you',
    query: { plan: plan.value?.name },
  })
}
</script>
