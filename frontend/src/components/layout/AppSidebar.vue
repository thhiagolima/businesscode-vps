<template>
  <div class="container-fluid d-flex flex-column h-100" style="padding-top:0.75rem">
    <div class="px-3 mb-3" style="padding-top:0.75rem">
      <a class="d-flex align-items-center gap-2 text-decoration-none" href="/" @click.prevent="go('/dashboard')">
        <div style="width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,#0064ff,#0054d8);display:flex;align-items:center;justify-content:center">
          <i class="ti ti-terminal" style="color:#fff;font-size:0.9rem"></i>
        </div>
        <div>
          <div class="text-white" style="font-family:'Manrope',sans-serif;font-weight:800;font-size:1.05rem;letter-spacing:-0.02em;line-height:1.1">
            <template v-if="identity?.brand_name">{{ identity.brand_name }}</template>
            <template v-else>&nbsp;</template>
          </div>
          <div style="font-size:0.6rem;text-transform:uppercase;letter-spacing:0.2em;color:var(--bc-text-muted,#8c90a2);font-weight:600;line-height:1">Plataforma Multicanal</div>
        </div>
      </a>
      <button class="btn-close btn-close-white d-lg-none position-absolute" type="button" data-bs-dismiss="offcanvas" aria-label="Fechar" style="top:1rem;right:1rem"></button>
    </div>

    <div class="navbar-collapse" id="sidebar-menu">
      <ul class="navbar-nav pt-lg-3" role="navigation" aria-label="Menu principal">
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/dashboard') }" @click.prevent="go('/dashboard')" role="link">
            <span class="nav-link-icon"><i class="ti ti-dashboard"></i></span>
            <span class="nav-link-title">Dashboard</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/contacts') }" @click.prevent="go('/contacts')" role="link">
            <span class="nav-link-icon"><i class="ti ti-users"></i></span>
            <span class="nav-link-title">Contatos</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/campaigns') }" @click.prevent="go('/campaigns')" role="link">
            <span class="nav-link-icon"><i class="ti ti-speakerphone"></i></span>
            <span class="nav-link-title">Campanhas</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/conversations') }" @click.prevent="go('/conversations')" role="link">
            <span class="nav-link-icon"><i class="ti ti-messages"></i></span>
            <span class="nav-link-title">Conversas</span>
            <span v-if="unreadCount > 0" class="badge bg-red ms-auto">{{ unreadCount }}</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/funnels') }" @click.prevent="go('/funnels')" role="link">
            <span class="nav-link-icon"><i class="ti ti-chart-funnel"></i></span>
            <span class="nav-link-title">Funis</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/chatbot/settings') }" @click.prevent="go('/chatbot/settings')" role="link">
            <span class="nav-link-icon"><i class="ti ti-robot"></i></span>
            <span class="nav-link-title">Chatbot</span>
          </a>
        </li>
        <li class="nav-item" :class="{ active: route.path.startsWith('/reports') }">
          <a class="nav-link" data-bs-toggle="collapse" href="#reportsSub" role="button"
             :aria-expanded="route.path.startsWith('/reports')">
            <span class="nav-link-icon"><i class="ti ti-report-analytics"></i></span>
            <span class="nav-link-title">Relatórios</span>
          </a>
          <div class="collapse" :class="{ show: route.path.startsWith('/reports') }" id="reportsSub">
            <ul class="nav nav-sm flex-column">
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/reports/campaigns') }" href="#" @click.prevent="go('/reports/campaigns')">Campanhas</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/reports/credits') }" href="#" @click.prevent="go('/reports/credits')">Extrato</a>
              </li>
            </ul>
          </div>
        </li>

        <li class="nav-item bc-section-label"><span>Conta</span></li>

        <li class="nav-item" :class="{ active: isInSettings }">
          <a class="nav-link" data-bs-toggle="collapse" href="#settingsSub" role="button"
             :aria-expanded="isInSettings">
            <span class="nav-link-icon"><i class="ti ti-settings"></i></span>
            <span class="nav-link-title">Configurações</span>
          </a>
          <div class="collapse" :class="{ show: isInSettings }" id="settingsSub">
            <ul class="nav nav-sm flex-column">
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/settings/plans') }" href="#" @click.prevent="go('/settings/plans')">Meu Plano</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/settings/whatsapp') }" href="#" @click.prevent="go('/settings/whatsapp')">WhatsApp</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/settings/email-domains') }" href="#" @click.prevent="go('/settings/email-domains')">Domínios de Email</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/settings/api-tokens') }" href="#" @click.prevent="go('/settings/api-tokens')">Tokens de API</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/settings/webhooks') }" href="#" @click.prevent="go('/settings/webhooks')">Webhooks</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/settings/opt-outs') }" href="#" @click.prevent="go('/settings/opt-outs')">Opt-outs (LGPD)</a>
              </li>
              <li class="nav-item" v-if="isAdminOrSuperadmin">
                <a class="nav-link" :class="{ active: isActive('/settings/audit-log') }" href="#" @click.prevent="go('/settings/audit-log')">Auditoria</a>
              </li>
            </ul>
          </div>
        </li>

        <li class="nav-item bc-section-label" v-if="isFinanceOrSuperadmin"><span>Administração</span></li>

        <li class="nav-item" :class="{ active: route.path.startsWith('/admin/billing') }" v-if="isFinanceOrSuperadmin">
          <a class="nav-link" data-bs-toggle="collapse" href="#financeSub" role="button"
             :aria-expanded="route.path.startsWith('/admin/billing')">
            <span class="nav-link-icon"><i class="ti ti-cash"></i></span>
            <span class="nav-link-title">Financeiro</span>
          </a>
          <div class="collapse" :class="{ show: route.path.startsWith('/admin/billing') }" id="financeSub">
            <ul class="nav nav-sm flex-column">
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/billing/pricing') }" href="#" @click.prevent="go('/admin/billing/pricing')">Preços de Serviços</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: route.path.startsWith('/admin/billing/tenants') }" href="#" @click.prevent="go('/admin/billing/tenants')">Cobrança por Tenant</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/billing/reports') }" href="#" @click.prevent="go('/admin/billing/reports')">Relatórios</a>
              </li>
            </ul>
          </div>
        </li>

        <li class="nav-item" :class="{ active: isInPlatform }" v-if="isSuperadmin">
          <a class="nav-link" data-bs-toggle="collapse" href="#platformSub" role="button"
             :aria-expanded="isInPlatform">
            <span class="nav-link-icon"><i class="ti ti-building"></i></span>
            <span class="nav-link-title">Plataforma</span>
          </a>
          <div class="collapse" :class="{ show: isInPlatform }" id="platformSub">
            <ul class="nav nav-sm flex-column">
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/tenants') }" href="#" @click.prevent="go('/admin/tenants')">Tenants</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/plans') }" href="#" @click.prevent="go('/admin/plans')">Planos</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/ai-personas') }" href="#" @click.prevent="go('/admin/ai-personas')">Personas IA</a>
              </li>
            </ul>
          </div>
        </li>

        <li class="nav-item" :class="{ active: isInIntegrations }" v-if="isSuperadmin">
          <a class="nav-link" data-bs-toggle="collapse" href="#integrationsSub" role="button"
             :aria-expanded="isInIntegrations">
            <span class="nav-link-icon"><i class="ti ti-plug"></i></span>
            <span class="nav-link-title">Integrações</span>
          </a>
          <div class="collapse" :class="{ show: isInIntegrations }" id="integrationsSub">
            <ul class="nav nav-sm flex-column">
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/settings/whatsapp') }" href="#" @click.prevent="go('/admin/settings/whatsapp')">WhatsApp (Meta)</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/infobip-whatsapp') }" href="#" @click.prevent="go('/admin/infobip-whatsapp')">WhatsApp (Infobip)</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/infobip-email') }" href="#" @click.prevent="go('/admin/infobip-email')">Email (Infobip)</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/settings/infobip') }" href="#" @click.prevent="go('/admin/settings/infobip')">SMS / Infobip</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/settings/ai') }" href="#" @click.prevent="go('/admin/settings/ai')">IA (Grok)</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/settings/elevenlabs') }" href="#" @click.prevent="go('/admin/settings/elevenlabs')">Voz (ElevenLabs)</a>
              </li>
            </ul>
          </div>
        </li>
      </ul>
    </div>

    <div class="mt-auto px-3 pb-3">
      <a
        href="#"
        @click.prevent="go('/settings/saldo')"
        class="d-flex align-items-center justify-content-between mb-3 text-decoration-none"
        :class="{ 'bc-saldo-active': isActive('/settings/saldo') }"
        style="padding:0.5rem 0.75rem;background:var(--bc-surface-container,#1a1e37);border-radius:var(--bc-radius,10px)"
      >
        <span class="d-flex align-items-center gap-2" style="font-size:0.75rem;color:var(--bc-text-muted,#8c90a2)">
          <i class="ti ti-wallet" style="color:var(--bc-primary,#0064ff)"></i>
          <span style="font-family:'JetBrains Mono',monospace;font-weight:600;color:var(--bc-text,#dee0ff)">{{ balanceLabel }}</span>
        </span>
        <span style="font-size:0.65rem;color:var(--bc-text-muted,#8c90a2);text-transform:uppercase;letter-spacing:0.1em">Meu Saldo</span>
      </a>

      <a href="#" @click.prevent="go('/profile')" class="d-flex align-items-center gap-2 text-decoration-none bc-user-link">
        <span class="avatar avatar-sm" style="background:linear-gradient(135deg,#0064ff,#0054d8);color:#fff;font-weight:700;font-size:0.65rem;width:32px;height:32px;border-radius:10px">{{ userInitials }}</span>
        <div class="flex-fill" style="min-width:0">
          <div class="text-white text-truncate" style="font-size:0.82rem;font-weight:600;line-height:1.2">{{ userLabel }}</div>
          <div class="text-truncate" style="font-size:0.68rem;color:var(--bc-text-muted,#8c90a2);line-height:1.2">{{ auth.user?.email }}</div>
        </div>
      </a>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useApi } from '@/composables/useApi'
import { useIdentity } from '@/composables/useIdentity'
import { brl } from '@/utils/currency'

const { identity } = useIdentity()
const emit = defineEmits<{ navigate: [] }>()

const { get } = useApi()
const unreadCount = ref(0)
let badgeTimer: any

async function fetchUnread() {
  try {
    const res = await get<any>('/conversations/unread-count')
    unreadCount.value = res?.count ?? 0
  } catch { /* silent */ }
}

onMounted(() => {
  fetchUnread()
  badgeTimer = setInterval(fetchUnread, 10000)
})

onUnmounted(() => {
  if (badgeTimer) clearInterval(badgeTimer)
})

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const isSuperadmin = computed(() => auth.user?.role === 'superadmin')
const isFinanceOrSuperadmin = computed(() => ['superadmin', 'finance'].includes(auth.user?.role || ''))
const isAdminOrSuperadmin = computed(() => ['superadmin', 'admin'].includes(auth.user?.role || ''))

const SETTINGS_PREFIXES = [
  '/settings/plans',
  '/settings/whatsapp',
  '/settings/email-domains',
  '/settings/api-tokens',
  '/settings/webhooks',
  '/settings/opt-outs',
  '/settings/audit-log',
]
const PLATFORM_PREFIXES = ['/admin/tenants', '/admin/plans', '/admin/ai-personas']
const INTEGRATIONS_PREFIXES = [
  '/admin/settings/whatsapp',
  '/admin/settings/infobip',
  '/admin/settings/ai',
  '/admin/settings/elevenlabs',
  '/admin/infobip-whatsapp',
  '/admin/infobip-email',
]

const matchesPrefix = (prefixes: string[]) =>
  prefixes.some(p => route.path === p || route.path.startsWith(p + '/'))

const isInSettings = computed(() => matchesPrefix(SETTINGS_PREFIXES))
const isInPlatform = computed(() => matchesPrefix(PLATFORM_PREFIXES))
const isInIntegrations = computed(() => matchesPrefix(INTEGRATIONS_PREFIXES))

const balanceLabel = computed(() => {
  const tenant = auth.user?.tenant as any
  const cents = tenant?.balance_cents ?? 0
  if (cents === -1) return '∞'
  return brl(cents)
})
const userLabel = computed(() => auth.user?.name ?? '')
const userInitials = computed(() => {
  const name = auth.user?.name || auth.user?.email || ''
  const parts = name.trim().split(' ')
  const first = parts[0]?.charAt(0) ?? ''
  const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : ''
  return (first + last).toUpperCase()
})

const go = (path: string) => {
  router.push(path)
  emit('navigate')
  const drawer = document.getElementById('mobile-drawer')
  if (drawer) {
    const instance = (window as any).bootstrap?.Offcanvas?.getOrCreateInstance(drawer)
    instance?.hide()
  }
}
const isActive = (path: string) => route.path === path
</script>

<style scoped>
.bc-user-link {
  padding: 0.5rem 0.6rem;
  border-radius: 8px;
  transition: background 0.2s ease;
}
.bc-user-link:hover {
  background: rgba(255,255,255,0.06);
}

.bc-section-label {
  padding: 1rem 1rem 0.4rem;
  pointer-events: none;
}
.bc-section-label span {
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.15em;
  color: var(--bc-text-muted, #8c90a2);
  opacity: 0.5;
}

/* Mobile drawer: ensure full height + scroll + touch-friendly spacing */
:deep(.offcanvas) .container-fluid {
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}

/* Touch-friendly nav links on mobile (min 44px height) */
@media (max-width: 1023px) {
  .nav-link {
    padding: 0.65rem 1rem !important;
    min-height: 44px;
    display: flex;
    align-items: center;
  }
  .nav-link-title {
    font-size: 0.95rem;
  }
}
</style>
