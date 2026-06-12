<template>
  <div
    v-if="banner"
    :class="['bc-balance-banner', `bc-balance-banner--${banner.color}`]"
    role="alert"
  >
    <i :class="banner.icon" class="me-2"></i>
    <span>{{ banner.text }}</span>
    <router-link v-if="banner.cta" to="/settings/saldo" class="ms-2 bc-balance-banner__cta">
      {{ banner.cta }}
      <i class="ti ti-arrow-right ms-1"></i>
    </router-link>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

type BannerColor = 'info' | 'warning' | 'danger'
type Banner = { color: BannerColor; text: string; cta?: string; icon: string } | null

const auth = useAuthStore()

const banner = computed<Banner>(() => {
  const t = auth.user?.tenant as any
  if (!t) return null

  const status = t.billing_status as string | undefined
  const balance = typeof t.balance_cents === 'number' ? t.balance_cents : 0
  const limit = typeof t.credit_limit_cents === 'number' ? t.credit_limit_cents : 0

  if (status === 'blocked') {
    return { color: 'danger', text: 'Conta bloqueada por inadimplência.', cta: 'Regularizar agora', icon: 'ti ti-lock' }
  }
  if (status === 'suspended') {
    return { color: 'danger', text: 'Conta suspensa por falta de pagamento.', cta: 'Regularizar', icon: 'ti ti-lock' }
  }
  if (status === 'grace') {
    return { color: 'warning', text: 'Pagamento pendente — sua conta entra em suspensão em breve.', cta: 'Atualizar cartão', icon: 'ti ti-alert-triangle' }
  }

  if (balance < 0 && limit > 0) {
    const used = Math.min(100, Math.round((Math.abs(balance) / limit) * 100))
    if (used >= 100) {
      return { color: 'danger', text: 'Limite de crédito atingido — suas campanhas serão pausadas.', cta: 'Recarregar saldo', icon: 'ti ti-alert-octagon' }
    }
    if (used >= 80) {
      return { color: 'warning', text: `Você já usou ${used}% da sua linha de crédito.`, cta: 'Recarregar saldo', icon: 'ti ti-alert-triangle' }
    }
    if (used >= 50) {
      return { color: 'info', text: `Você usou ${used}% da sua linha de crédito.`, icon: 'ti ti-info-circle' }
    }
  }

  // P0-26: contas PREPAID (sem credit_limit) também precisam de aviso quando o saldo
  // está baixo, pois nunca chegam ao negativo — risco de surpresa no meio do disparo.
  // Thresholds: R$ 0,00 → "sem saldo"; <R$ 10,00 → "saldo baixo"; <R$ 50,00 → "atenção".
  if (limit === 0) {
    if (balance <= 0) {
      return { color: 'danger', text: 'Sem saldo. Recarregue para enviar novas campanhas.', cta: 'Recarregar agora', icon: 'ti ti-alert-octagon' }
    }
    if (balance < 1000) {
      return { color: 'warning', text: `Saldo crítico: R$ ${(balance/100).toFixed(2).replace('.', ',')}. Recarregue para evitar interrupção.`, cta: 'Recarregar saldo', icon: 'ti ti-alert-triangle' }
    }
    if (balance < 5000) {
      return { color: 'info', text: `Saldo baixo: R$ ${(balance/100).toFixed(2).replace('.', ',')}.`, cta: 'Recarregar saldo', icon: 'ti ti-info-circle' }
    }
  }

  return null
})
</script>

<style scoped>
.bc-balance-banner {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.6rem 1rem;
  font-size: 0.85rem;
  font-weight: 500;
  border-bottom: 1px solid transparent;
  flex-wrap: wrap;
  z-index: 100;
  position: relative;
}
.bc-balance-banner--info {
  background: rgba(0, 100, 255, 0.08);
  color: #4dabff;
  border-bottom-color: rgba(0, 100, 255, 0.2);
}
.bc-balance-banner--warning {
  background: rgba(255, 152, 0, 0.1);
  color: #ffb547;
  border-bottom-color: rgba(255, 152, 0, 0.25);
}
.bc-balance-banner--danger {
  background: rgba(239, 68, 68, 0.1);
  color: #ff6b6b;
  border-bottom-color: rgba(239, 68, 68, 0.25);
}
.bc-balance-banner__cta {
  font-weight: 700;
  color: inherit;
  text-decoration: underline;
  text-underline-offset: 2px;
}
.bc-balance-banner__cta:hover {
  color: inherit;
  opacity: 0.85;
}
</style>
