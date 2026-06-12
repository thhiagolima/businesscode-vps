import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'

export interface CheckoutConfig {
  mp_public_key: string
  credit_unit_price: number
  credit_min_purchase: number
}

export interface CouponData {
  id: number
  code: string
  discount_type: 'percentage' | 'fixed'
  discount_value: number
}

export interface PixData {
  payment_id: number
  mp_payment_id: string
  status: string
  pix_qr_code: string
  pix_qr_code_base64: string
  pix_expiration: string
}

export interface BoletoData {
  payment_id: number
  status: string
  boleto_url: string
  boleto_barcode: string
}

export const useCheckoutStore = defineStore('checkout', () => {
  const { get, post } = useApi()

  const config = ref<CheckoutConfig | null>(null)
  const coupon = ref<CouponData | null>(null)
  const calculatedDiscount = ref<number>(0)
  const paymentStatus = ref<'idle' | 'processing' | 'success' | 'error'>('idle')
  const errorMessage = ref<string>('')
  const pixData = ref<PixData | null>(null)
  const boletoData = ref<BoletoData | null>(null)
  const pollingInterval = ref<ReturnType<typeof setInterval> | null>(null)

  async function fetchConfig() {
    if (config.value) return config.value
    config.value = await get<CheckoutConfig>('/checkout/config')
    return config.value
  }

  async function validateCoupon(code: string, planId?: number): Promise<boolean> {
    try {
      const res = await post<{ coupon: CouponData; calculated_discount: number | null }>('/checkout/validate-coupon', {
        code,
        plan_id: planId,
      })
      coupon.value = res.coupon
      calculatedDiscount.value = res.calculated_discount ?? 0
      return true
    } catch (e: any) {
      coupon.value = null
      calculatedDiscount.value = 0
      errorMessage.value = e?.response?.data?.message || 'Cupom inválido.'
      return false
    }
  }

  function clearCoupon() {
    coupon.value = null
    calculatedDiscount.value = 0
  }

  async function createSubscription(
    planId: number,
    cardToken: string,
    payerEmail: string,
    couponCode?: string,
    billingCycle: 'monthly' | 'annual' = 'monthly',
    saveCardForBilling: boolean = true,
  ) {
    paymentStatus.value = 'processing'
    errorMessage.value = ''
    try {
      const res = await post<any>('/subscriptions', {
        plan_id: planId,
        card_token: cardToken,
        payer_email: payerEmail,
        coupon_code: couponCode,
        billing_cycle: billingCycle,
        save_card_for_billing: saveCardForBilling,
      })
      // A assinatura pode voltar 'active' (autorizada na hora) ou 'pending'
      // (aguardando confirmação do MP). Só marcamos sucesso quando ativa;
      // pendente fica em 'processing' para o chamador iniciar o polling.
      paymentStatus.value = res?.status === 'active' ? 'success' : 'processing'
      return res
    } catch (e: any) {
      paymentStatus.value = 'error'
      errorMessage.value = e?.response?.data?.message || 'Erro ao processar pagamento.'
      throw e
    }
  }

  async function createPixPayment(planId: number | null, payerEmail: string, creditsAmount?: number, billingCycle: 'monthly' | 'annual' = 'monthly') {
    paymentStatus.value = 'processing'
    errorMessage.value = ''
    try {
      const payload: Record<string, unknown> = { payer_email: payerEmail }
      if (creditsAmount) {
        payload.credits_amount = creditsAmount
      } else {
        payload.plan_id = planId
        payload.billing_cycle = billingCycle
      }
      const res = await post<PixData>('/payments/pix', payload)
      pixData.value = res
      paymentStatus.value = 'idle'
      return res
    } catch (e: any) {
      paymentStatus.value = 'error'
      errorMessage.value = e?.response?.data?.message || 'Erro ao gerar PIX.'
      throw e
    }
  }

  async function createBoletoPayment(planId: number | null, payerEmail: string, payerDocument: string, creditsAmount?: number, billingCycle: 'monthly' | 'annual' = 'monthly') {
    paymentStatus.value = 'processing'
    errorMessage.value = ''
    try {
      const payload: Record<string, unknown> = { payer_email: payerEmail, payer_document: payerDocument }
      if (creditsAmount) {
        payload.credits_amount = creditsAmount
      } else {
        payload.plan_id = planId
        payload.billing_cycle = billingCycle
      }
      const res = await post<BoletoData>('/payments/boleto', payload)
      boletoData.value = res
      paymentStatus.value = 'idle'
      return res
    } catch (e: any) {
      paymentStatus.value = 'error'
      errorMessage.value = e?.response?.data?.message || 'Erro ao gerar boleto.'
      throw e
    }
  }

  async function purchaseCredits(
    /** Valor a debitar em CENTAVOS (R$ 50,00 = 5000). */
    amountCents: number,
    method: string,
    payerEmail: string,
    cardToken?: string,
    payerDocument?: string,
    saveCardForBilling: boolean = false,
  ) {
    paymentStatus.value = 'processing'
    errorMessage.value = ''
    try {
      const res = await post<any>('/payments/credits', {
        // Enviamos os DOIS nomes para resistir a divergência de contrato:
        // o backend aceita ambos via `amount_cents ?? credits_amount`.
        amount_cents: amountCents,
        credits_amount: amountCents,
        payment_method: method,
        card_token: cardToken,
        payer_email: payerEmail,
        payer_document: payerDocument,
        save_card_for_billing: saveCardForBilling,
      })

      if (res.pix_qr_code) {
        pixData.value = res
      } else if (res.boleto_url) {
        boletoData.value = res
      } else if (res.status === 'approved') {
        paymentStatus.value = 'success'
      } else {
        // Cartão aprovado de forma assíncrona (status 'pending'): mantemos
        // 'processing' para o chamador acompanhar via pollPaymentStatus.
        paymentStatus.value = 'processing'
      }
      return res
    } catch (e: any) {
      paymentStatus.value = 'error'
      errorMessage.value = e?.response?.data?.message || 'Erro ao processar pagamento.'
      throw e
    }
  }

  async function pollPaymentStatus(paymentId: number, onApproved: () => void) {
    let failures = 0
    const maxPolls = 360 // 30 minutes at 5s intervals
    let pollCount = 0
    stopPolling()
    pollingInterval.value = setInterval(async () => {
      pollCount++
      if (pollCount >= maxPolls) {
        stopPolling()
        errorMessage.value = 'Tempo de espera esgotado. Verifique seu e-mail para confirmação.'
        return
      }
      try {
        const res = await get<{ status: string }>(`/payments/${paymentId}/status`)
        if (res.status === 'approved') {
          stopPolling()
          paymentStatus.value = 'success'
          onApproved()
        }
        failures = 0
      } catch {
        failures++
        if (failures >= 3) {
          stopPolling()
          errorMessage.value = 'Não foi possível verificar o status do pagamento.'
        }
      }
    }, 5000)
  }

  // Polling para assinaturas por cartão que voltam 'pending' do MercadoPago.
  // Diferente de pollPaymentStatus (que consulta /payments/{id}/status), aqui
  // verificamos /subscriptions/current — que só retorna a assinatura quando ela
  // fica 'active'/'authorized'. Enquanto pendente, o endpoint devolve null.
  async function pollSubscriptionActive(onActive: () => void) {
    let failures = 0
    const maxPolls = 60 // 5 minutes at 5s intervals
    let pollCount = 0
    stopPolling()
    pollingInterval.value = setInterval(async () => {
      pollCount++
      if (pollCount >= maxPolls) {
        stopPolling()
        errorMessage.value = 'Pagamento em processamento. Você receberá a confirmação por e-mail em instantes.'
        return
      }
      try {
        const sub = await get<{ id: number } | null>('/subscriptions/current')
        if (sub && sub.id) {
          stopPolling()
          paymentStatus.value = 'success'
          onActive()
        }
        failures = 0
      } catch {
        failures++
        if (failures >= 3) {
          stopPolling()
          errorMessage.value = 'Não foi possível verificar o status da assinatura.'
        }
      }
    }, 5000)
  }

  function stopPolling() {
    if (pollingInterval.value) {
      clearInterval(pollingInterval.value)
      pollingInterval.value = null
    }
  }

  function reset() {
    coupon.value = null
    calculatedDiscount.value = 0
    paymentStatus.value = 'idle'
    errorMessage.value = ''
    pixData.value = null
    boletoData.value = null
    stopPolling()
  }

  return {
    config, coupon, calculatedDiscount, paymentStatus, errorMessage, pixData, boletoData,
    fetchConfig, validateCoupon, clearCoupon,
    createSubscription, createPixPayment, createBoletoPayment, purchaseCredits,
    pollPaymentStatus, pollSubscriptionActive, stopPolling, reset,
  }
})
