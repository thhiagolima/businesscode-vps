<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-.03em;margin:0">Planos</h2>
        <span style="font-size:.85rem;color:var(--bc-text-muted)">Escolha o plano ideal para seu negócio</span>
      </div>
    </div>

    <!-- Current plan badge -->
    <div v-if="currentPlan" class="mb-4 p-3 d-flex align-items-center justify-content-between" style="background:rgba(0,100,255,0.04);border:1px solid rgba(0,100,255,0.1);border-radius:12px">
      <div class="d-flex align-items-center gap-3">
        <div style="width:40px;height:40px;border-radius:10px;background:rgba(0,100,255,0.08);display:flex;align-items:center;justify-content:center">
          <i class="ti ti-crown" style="color:#0064ff;font-size:1.2rem"></i>
        </div>
        <div>
          <div style="font-weight:700;font-size:.95rem">Plano atual: {{ currentPlan.name }}</div>
          <div style="font-size:.82rem;color:var(--bc-text-muted)">
            {{ brl(currentPlan.credits_included) }} de saldo · {{ currentPlan.max_contacts || '∞' }} contatos
          </div>
        </div>
      </div>
      <div class="text-mono" style="font-size:1.1rem;font-weight:800;color:var(--bc-text)">
        {{ currentPlan.price_monthly > 0 ? `R$${currentPlan.price_monthly}/mês` : 'Grátis' }}
      </div>
    </div>

    <!-- Plans grid -->
    <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

    <div v-else-if="!plans.length" class="text-center py-5" style="background:var(--bc-gray-soft);border-radius:14px">
      <i class="ti ti-package" style="font-size:2.5rem;color:var(--bc-text-muted);display:block;margin-bottom:.75rem"></i>
      <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:.25rem">Nenhum plano disponível</h3>
      <p style="font-size:.85rem;color:var(--bc-text-muted);max-width:420px;margin:0 auto 1rem">
        Você está usando o modelo pré-pago. Recarregue saldo conforme sua necessidade.
      </p>
      <router-link to="/settings/saldo" class="btn btn-primary" style="border-radius:10px">
        <i class="ti ti-wallet me-1"></i> Recarregar Saldo
      </router-link>
    </div>

    <div v-else class="row g-3">
      <div v-for="plan in plans" :key="plan.id" class="col-12 col-md-6 col-xl-3">
        <div class="bc-plan-card" :class="{ 'bc-plan-current': plan.slug === currentPlan?.slug, 'bc-plan-popular': plan.slug === 'pro' }">
          <div v-if="plan.slug === 'pro'" class="bc-plan-badge">Mais popular</div>
          <div v-if="plan.slug === currentPlan?.slug" class="bc-plan-badge bc-plan-badge-current">Seu plano</div>

          <div class="bc-plan-name">{{ plan.name }}</div>
          <div class="bc-plan-price">
            <span v-if="isSalesContact(plan)" class="bc-plan-amount" style="font-size:1.7rem">Sob consulta</span>
            <span v-else-if="plan.price_monthly > 0">
              <span class="bc-plan-currency">R$</span>
              <span class="bc-plan-amount">{{ Math.floor(plan.price_monthly) }}</span>
              <span class="bc-plan-period">/mês</span>
            </span>
            <span v-else class="bc-plan-amount">Grátis</span>
          </div>
          <div v-if="!isSalesContact(plan)" class="bc-plan-credits">{{ brl(plan.credits_included) }} de saldo{{ plan.price_monthly > 0 ? '/mês' : '' }}</div>

          <ul class="bc-plan-features">
            <li v-for="feat in planFeatures(plan)" :key="feat.text">
              <i :class="feat.included ? 'ti ti-check text-success' : 'ti ti-x'" :style="{ opacity: feat.included ? 1 : 0.2 }"></i>
              <span :style="{ opacity: feat.included ? 1 : 0.4 }">{{ feat.text }}</span>
            </li>
          </ul>

          <button v-if="plan.slug === currentPlan?.slug" class="btn btn-outline-secondary w-100" style="border-radius:10px" disabled>
            Plano atual
          </button>
          <a v-else-if="isSalesContact(plan)" :href="salesHref" class="btn btn-outline-primary w-100" style="border-radius:10px">
            <i class="ti ti-headset me-1"></i> Falar com vendas
          </a>
          <button v-else-if="plan.price_monthly === 0" class="btn btn-outline-secondary w-100" style="border-radius:10px" disabled>
            Plano grátis
          </button>
          <router-link v-else :to="`/settings/checkout/${plan.slug}`" class="btn btn-primary w-100" style="border-radius:10px">
            <i class="ti ti-arrow-up-right me-1"></i> Fazer Upgrade
          </router-link>
        </div>
      </div>
    </div>

    <!-- Buy credits button -->
    <div class="text-center mt-4">
      <router-link to="/settings/saldo" class="btn btn-outline-primary" style="border-radius:10px;padding:.6rem 2rem">
        <i class="ti ti-wallet me-2"></i>Recarregar Saldo
      </router-link>
    </div>

    <!-- Pricing info (dinâmico via /account/pricing — sem hardcode) -->
    <div class="mt-4 text-center" style="font-size:.85rem;color:var(--bc-text-muted)">
      <p v-if="pricingLoading">
        <span class="spinner-border spinner-border-sm me-2"></span>Carregando tarifas…
      </p>
      <p v-else-if="pricingItems.length">
        <span v-for="(item, idx) in pricingItems" :key="item.service">
          {{ item.label }} {{ item.sale_brl }}<template v-if="idx < pricingItems.length - 1"> · </template>
        </span>
        <span> por envio</span>
      </p>
      <p v-if="supportEmail">
        Dúvidas? <a :href="`mailto:${supportEmail}`" style="color:#0064ff">Fale com o suporte</a>
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import { brl } from '@/utils/currency'

const { get } = useApi()
const auth = useAuthStore()
const plans = ref<any[]>([])
const loading = ref(true)

// Tarifa dinâmica por canal — fonte única `/account/pricing` (P0-03).
const SERVICE_LABEL: Record<string, string> = {
  sms:           'SMS',
  voice:         'Voz',
  email:         'Email',
  whatsapp:      'WhatsApp',
  ai_generation: 'IA',
  audio_tts:     'TTS',
}
const pricingItems = ref<Array<{ service: string; label: string; sale_brl: string }>>([])
const pricingLoading = ref(true)
const supportEmail = (import.meta as any).env?.VITE_SUPPORT_EMAIL ?? ''
const salesHref = computed(() => supportEmail ? `mailto:${supportEmail}?subject=Plano%20Enterprise` : '#')

// Planos "sob consulta" (ex.: Enterprise) não exibem preço/Grátis — exigem contato com vendas.
function isSalesContact(plan: any): boolean {
  const f = typeof plan.features === 'string' ? JSON.parse(plan.features) : (plan.features ?? {})
  return f?.sales_contact_only === true
}

const currentPlan = computed(() => {
  const planId = auth.user?.tenant?.plan_id
  return plans.value.find(p => p.id === planId) ?? null
})

function planFeatures(plan: any): Array<{ text: string; included: boolean }> {
  const f = typeof plan.features === 'string' ? JSON.parse(plan.features) : (plan.features ?? {})
  const channels = f.channels ?? []
  return [
    { text: `${plan.max_contacts || '∞'} contatos`, included: true },
    { text: `${plan.max_campaigns || '∞'} campanhas/mês`, included: true },
    { text: 'SMS', included: channels.includes('sms') },
    { text: 'Torpedo de Voz', included: channels.includes('voice') },
    { text: 'WhatsApp', included: channels.includes('whatsapp') },
    { text: 'Email', included: channels.includes('email') },
    { text: 'Chatbot IA', included: f.ai_chatbot_enabled === true },
    { text: 'Funis automáticos', included: f.funnels_enabled === true },
    { text: 'API access', included: f.api_access === true },
    { text: 'White-label', included: f.white_label === true },
  ]
}

onMounted(async () => {
  try {
    const res = await get<any>('/plans')
    plans.value = Array.isArray(res) ? res : (res?.data ?? [])
  } catch {
    plans.value = []
  } finally {
    loading.value = false
  }

  try {
    const r = await get<any>('/account/pricing')
    const list = Array.isArray(r) ? r : (r?.data ?? [])
    pricingItems.value = list
      .filter((p: any) => ['sms', 'voice', 'email', 'whatsapp'].includes(p.service))
      .map((p: any) => ({
        service: p.service,
        label: SERVICE_LABEL[p.service] ?? p.service,
        sale_brl: p.sale_brl,
      }))
  } catch {
    pricingItems.value = []
  } finally {
    pricingLoading.value = false
  }
})
</script>

<style scoped>
.bc-plan-card {
  background: var(--bc-gray);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 16px;
  padding: 28px 24px;
  height: 100%;
  display: flex;
  flex-direction: column;
  position: relative;
  transition: all .2s;
}
.bc-plan-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(0,0,0,.25);
}
.bc-plan-popular {
  border-color: #0064ff;
  box-shadow: 0 0 0 1px #0064ff;
}
.bc-plan-current {
  border-color: rgba(13,204,106,.3);
}
.bc-plan-badge {
  position: absolute;
  top: -10px;
  left: 50%;
  transform: translateX(-50%);
  background: #0064ff;
  color: #fff;
  font-size: .65rem;
  font-weight: 800;
  padding: 3px 12px;
  border-radius: 50px;
  white-space: nowrap;
  letter-spacing: .03em;
}
.bc-plan-badge-current {
  background: #0dcc6a;
}
.bc-plan-name {
  font-size: .82rem;
  font-weight: 700;
  color: var(--bc-text-muted);
  text-transform: uppercase;
  letter-spacing: .05em;
  margin-bottom: 8px;
}
.bc-plan-price { margin-bottom: 4px; }
.bc-plan-currency { font-size: .9rem; color: var(--bc-text-muted); font-weight: 600; }
.bc-plan-amount { font-size: 2.2rem; font-weight: 800; color: var(--bc-text); font-family: 'JetBrains Mono', monospace; letter-spacing: -.02em; }
.bc-plan-period { font-size: .82rem; color: var(--bc-text-muted); }
.bc-plan-credits { font-size: .82rem; color: #0064ff; font-weight: 700; font-family: 'JetBrains Mono', monospace; margin-bottom: 20px; }
.bc-plan-features {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 24px;
  flex: 1;
}
.bc-plan-features li {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: .84rem;
}
.bc-plan-features .ti { font-size: .95rem; }

/* dark theme is the default — no overrides needed */
</style>
