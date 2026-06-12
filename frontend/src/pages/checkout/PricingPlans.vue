<template>
  <div style="min-height:100vh;background:#080c25;color:#dee0ff;padding-bottom:4rem">
    <nav style="position:fixed;top:0;width:100%;z-index:50;background:rgba(15,23,42,0.6);backdrop-filter:blur(16px);display:flex;justify-content:space-between;align-items:center;padding:0 2rem;height:64px">
      <div style="display:flex;align-items:center;gap:2rem">
        <span style="font-size:1.3rem;font-weight:800;letter-spacing:-.04em;color:white">
          <template v-if="identity?.brand_name">{{ identity.brand_name }}</template>
          <template v-else>&nbsp;</template>
        </span>
        <div style="display:flex;gap:1.5rem">
          <router-link v-if="isAuthenticated" to="/dashboard" style="color:rgba(148,163,184,1);font-weight:500;text-decoration:none">Dashboard</router-link>
          <a href="#" style="color:white;font-weight:700;text-decoration:none;border-bottom:2px solid #3b82f6;padding-bottom:2px">Planos</a>
        </div>
      </div>
      <div>
        <router-link v-if="!isAuthenticated" to="/login" class="btn btn-sm btn-primary" style="border-radius:8px">Entrar</router-link>
      </div>
    </nav>

    <main style="padding-top:8rem;padding-left:1.5rem;padding-right:1.5rem;max-width:1200px;margin:0 auto">
      <header style="text-align:center;margin-bottom:3rem">
        <h1 style="font-size:2.8rem;font-weight:800;letter-spacing:-.04em;margin-bottom:1rem;color:white">
          O Plano Ideal para seu <span style="color:#b3c5ff">Crescimento</span>
        </h1>
        <p style="font-size:1.1rem;color:rgba(194,198,216,1);max-width:600px;margin:0 auto">
          Infraestrutura escalável para marketing moderno. Escolha o plano que acompanha seu momento.
        </p>

        <div v-if="hasAnnualOption" style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-top:2rem">
          <span :style="{fontSize: '.9rem', fontWeight: 600, color: cycle === 'monthly' ? 'white' : 'rgba(194,198,216,1)'}">Mensal</span>
          <button @click="cycle = cycle === 'monthly' ? 'annual' : 'monthly'" style="position:relative;width:56px;height:32px;background:#242842;border-radius:999px;padding:4px;border:none;cursor:pointer" :aria-label="`Alternar para cobrança ${cycle === 'monthly' ? 'anual' : 'mensal'}`">
            <div :style="{ transform: cycle === 'annual' ? 'translateX(24px)' : 'translateX(0)', transition: 'transform .2s', width: '24px', height: '24px', background: '#0064ff', borderRadius: '999px' }"></div>
          </button>
          <div style="display:flex;align-items:center;gap:.5rem">
            <span :style="{fontSize:'.9rem', fontWeight: 600, color: cycle === 'annual' ? 'white' : 'rgba(194,198,216,1)'}">Anual</span>
            <span v-if="annualDiscountPercent" style="font-size:.65rem;font-weight:800;background:#0566d9;color:white;padding:2px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.05em">-{{ annualDiscountPercent }}%</span>
          </div>
        </div>
      </header>

      <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

      <div v-else-if="!plans.length" class="text-center py-5" style="color:rgba(194,198,216,1);max-width:520px;margin:0 auto">
        <i class="ti ti-rocket" style="font-size:2.5rem;color:#0064ff;display:block;margin-bottom:1rem"></i>
        <h2 style="font-size:1.4rem;font-weight:700;color:white;margin-bottom:0.5rem">Planos personalizados sob consulta</h2>
        <p style="font-size:.95rem;margin-bottom:1.5rem">
          Ainda estamos finalizando nossos planos comerciais. Enquanto isso, você já pode
          {{ isAuthenticated ? 'usar a plataforma com saldo pré-pago' : 'criar uma conta grátis e testar com saldo inicial' }}.
        </p>
        <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap">
          <router-link v-if="!isAuthenticated" to="/register" class="btn btn-primary" style="border-radius:10px;padding:.7rem 1.25rem;font-weight:700">
            Criar conta grátis
          </router-link>
          <router-link v-else to="/settings/saldo" class="btn btn-primary" style="border-radius:10px;padding:.7rem 1.25rem;font-weight:700">
            Recarregar saldo
          </router-link>
          <button v-if="identity?.sales_whatsapp" @click="contactSales" class="btn btn-outline-secondary" style="border-radius:10px;padding:.7rem 1.25rem;font-weight:700">
            <i class="ti ti-brand-whatsapp me-1"></i> Falar com Vendas
          </button>
        </div>
      </div>

      <div v-else style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:2rem;align-items:stretch">
        <div
          v-for="plan in visiblePlans"
          :key="plan.id"
          style="background:rgba(22,27,69,0.6);backdrop-filter:blur(12px);border-radius:16px;padding:2rem;display:flex;flex-direction:column;position:relative;transition:all .2s"
          :style="plan.slug === 'pro' ? 'border:2px solid #0064ff;box-shadow:0 0 32px rgba(0,100,255,0.15);transform:scale(1.02)' : 'border:1px solid rgba(255,255,255,0.06)'"
        >
          <div v-if="plan.slug === 'pro'" style="position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:#0064ff;color:white;font-size:.65rem;font-weight:800;padding:3px 14px;border-radius:999px;text-transform:uppercase;letter-spacing:.08em">
            Mais Popular
          </div>

          <div style="margin-bottom:1.5rem">
            <h3 style="font-size:1.3rem;font-weight:700;color:white;margin-bottom:.25rem">{{ plan.name }}</h3>
            <p style="font-size:.85rem;color:rgba(194,198,216,1)">{{ planDescription(plan) }}</p>
          </div>

          <div style="margin-bottom:1.5rem">
            <span v-if="plan.price_monthly > 0" style="display:flex;align-items:baseline;gap:.25rem">
              <span style="font-size:.9rem;color:rgba(194,198,216,1)">R$</span>
              <span style="font-size:2.5rem;font-weight:800;color:white;letter-spacing:-.02em">{{ displayPrice(plan) }}</span>
              <span style="font-size:.85rem;color:rgba(194,198,216,1)">/mês</span>
            </span>
            <span v-else style="font-size:2.5rem;font-weight:800;color:white">Grátis</span>
            <div v-if="cycle === 'annual' && plan.price_monthly > 0" style="font-size:.75rem;color:#b3c5ff;font-weight:700;margin-top:.25rem">
              Cobrado anualmente: R$ {{ formatBrl(plan.price_annual || plan.price_monthly * 12) }}
            </div>
          </div>

          <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.5rem;flex:1">
            <li v-for="feat in planFeatures(plan)" :key="feat" style="display:flex;align-items:center;gap:.5rem;font-size:.88rem">
              <i class="ti ti-check" style="color:#0064ff;font-size:.9rem"></i>
              <span>{{ feat }}</span>
            </li>
          </ul>

          <button
            v-if="plan.slug === 'enterprise' && identity?.sales_whatsapp"
            class="btn btn-outline-secondary w-100"
            style="border-radius:10px;padding:.7rem"
            @click="contactSales"
          >
            Falar com Vendas
          </button>
          <button
            v-else-if="plan.price_monthly === 0"
            class="btn btn-outline-secondary w-100"
            style="border-radius:10px;padding:.7rem"
            disabled
          >
            Plano Atual
          </button>
          <router-link
            v-else
            :to="checkoutRoute(plan)"
            class="btn w-100"
            :class="plan.slug === 'pro' ? 'btn-primary' : 'btn-outline-primary'"
            style="border-radius:10px;padding:.7rem;font-weight:700"
          >
            Escolher Plano
          </router-link>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { brl } from '@/utils/currency'
import axios from 'axios'

interface PlanPayload {
  id: number
  name: string
  slug: string
  price_monthly: number
  price_annual: number | null
  monthly_equivalent_annual: number
  credits_included: number
  max_contacts: number
  max_campaigns: number
  features: Record<string, unknown>
}

interface Identity {
  brand_name: string
  sales_whatsapp: string
  sales_whatsapp_prompt: string
}

const auth = useAuthStore()
const isAuthenticated = computed(() => auth.isAuthenticated)

const plans = ref<PlanPayload[]>([])
const identity = ref<Identity | null>(null)
const annualDiscountPercent = ref<number>(0)
const loading = ref(true)
const cycle = ref<'monthly' | 'annual'>('monthly')

const hasAnnualOption = computed(() => plans.value.some(p => p.price_annual && p.price_annual > 0))
const visiblePlans = computed(() => plans.value)

onMounted(async () => {
  try {
    const [plansResp, identityResp] = await Promise.all([
      axios.get('/api/v1/plans'),
      axios.get('/api/v1/public/identity'),
    ])
    const plansBody = plansResp.data
    plans.value = Array.isArray(plansBody) ? plansBody : (plansBody?.data ?? [])
    annualDiscountPercent.value = plansBody?.meta?.annual_discount_percent ?? 0
    identity.value = identityResp.data
  } catch {
    plans.value = []
    identity.value = null
  } finally {
    loading.value = false
  }
})

function displayPrice(plan: PlanPayload): string {
  if (cycle.value === 'annual' && plan.monthly_equivalent_annual) {
    return plan.monthly_equivalent_annual.toFixed(0)
  }
  return Number(plan.price_monthly).toFixed(0)
}

function formatBrl(value: number): string {
  return Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function checkoutRoute(plan: PlanPayload): string {
  const base = isAuthenticated.value ? `/plans/checkout/${plan.slug}` : `/login?redirect=/plans/checkout/${plan.slug}`
  return cycle.value === 'annual' ? `${base}?cycle=annual` : base
}

function planDescription(plan: PlanPayload) {
  const map: Record<string, string> = {
    free: 'Para testar sem compromisso.',
    starter: 'Ideal para começar.',
    pro: 'Motor de alta performance.',
    business: 'Agências e alta escala.',
    enterprise: 'Solução personalizada.',
  }
  return map[plan.slug] || ''
}

function planFeatures(plan: PlanPayload): string[] {
  const feats: string[] = []
  const contacts = Number(plan.max_contacts)
  feats.push(`${contacts > 0 ? contacts.toLocaleString('pt-BR') : 'Ilimitados'} contatos`)
  feats.push(`${brl(Number(plan.credits_included))} de saldo/mês`)
  const f = (plan.features ?? {}) as Record<string, unknown>
  const channels = (f.channels as string[]) ?? []
  if (channels.length) feats.push(`Canais: ${channels.join(', ')}`)
  if (f.ai_chatbot_enabled) feats.push('Chatbot IA')
  if (f.funnels_enabled) feats.push('Funis automáticos')
  if (f.api_access) feats.push('Acesso à API')
  if (f.white_label) feats.push('White-label')
  return feats
}

function contactSales() {
  const whatsapp = identity.value?.sales_whatsapp
  if (!whatsapp) return
  const prompt = identity.value?.sales_whatsapp_prompt ?? ''
  const url = `https://wa.me/${whatsapp.replace(/\D/g, '')}?text=${encodeURIComponent(prompt)}`
  window.open(url, '_blank')
}
</script>
