import { createRouter, createWebHistory, RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    component: () => import('@/pages/auth/Login.vue'),
    meta: { public: true, title: 'Login' },
  },
  {
    path: '/register',
    component: () => import('@/pages/auth/Register.vue'),
    meta: { public: true, title: 'Criar conta' },
  },
  {
    path: '/',
    meta: { public: true },
    beforeEnter: () => {
      const auth = useAuthStore()
      if (auth.isAuthenticated) {
        return '/dashboard'
      }
      // In production, Apache serves landing.html for /
      // In dev, redirect to the static file
      window.location.href = '/landing.html'
      return false
    },
    component: () => import('@/pages/auth/Login.vue'), // fallback, never rendered
  },
  {
    path: '/dashboard',
    component: () => import('@/pages/dashboard/Index.vue'),
    meta: { title: 'Dashboard' },
  },
  {
    path: '/contacts',
    component: () => import('@/pages/contacts/Index.vue'),
    meta: { title: 'Contatos' },
  },
  {
    path: '/admin/settings/infobip',
    component: () => import('@/pages/admin/SettingsInfobip.vue'),
    meta: { superadmin: true, title: 'Settings • Infobip' },
  },
  {
    path: '/admin/settings/ai',
    component: () => import('@/pages/admin/SettingsAi.vue'),
    meta: { superadmin: true, title: 'Settings • IA / Grok' },
  },
  {
    path: '/admin/settings/elevenlabs',
    component: () => import('@/pages/admin/SettingsElevenLabs.vue'),
    meta: { superadmin: true, title: 'Settings • ElevenLabs' },
  },
  {
    path: '/admin/plans',
    component: () => import('@/pages/admin/Plans.vue'),
    meta: { superadmin: true, title: 'Admin • Planos' },
  },
  {
    path: '/admin/tenants',
    component: () => import('@/pages/admin/Tenants.vue'),
    meta: { superadmin: true, title: 'Admin • Tenants' },
  },
  {
    path: '/admin/tenants/:id',
    component: () => import('@/pages/admin/tenants/Detail.vue'),
    meta: { superadmin: true, title: 'Admin • Tenant' },
  },
  // Billing — Financeiro (role: finance | superadmin)
  {
    path: '/admin/billing/pricing',
    component: () => import('@/pages/admin/billing/Pricing.vue'),
    meta: { role: 'finance', title: 'Preços de Serviços' },
  },
  {
    path: '/admin/billing/tenants',
    component: () => import('@/pages/admin/billing/Tenants.vue'),
    meta: { role: 'finance', title: 'Tenants — Financeiro' },
  },
  {
    path: '/admin/billing/tenants/:id',
    component: () => import('@/pages/admin/billing/TenantDetail.vue'),
    meta: { role: 'finance', title: 'Tenant — Detalhe Financeiro' },
  },
  {
    path: '/admin/billing/reports',
    component: () => import('@/pages/admin/billing/Reports.vue'),
    meta: { role: 'finance', title: 'Relatórios Financeiros' },
  },
  {
    path: '/campaigns',
    component: () => import('@/pages/campaigns/Index.vue'),
    meta: { title: 'Campanhas' },
  },
  {
    path: '/campaigns/new',
    component: () => import('@/pages/campaigns/SelectChannel.vue'),
    meta: { title: 'Nova Campanha — Escolha o canal' },
  },
  {
    path: '/campaigns/create',
    component: () => import('@/pages/campaigns/Create.vue'),
    meta: { title: 'Nova Campanha' },
  },
  {
    path: '/campaigns/:id',
    component: () => import('@/pages/campaigns/Detail.vue'),
    meta: { title: 'Detalhes da Campanha' },
  },
  {
    path: '/ai',
    component: () => import('@/pages/ai/Index.vue'),
    meta: { title: 'Gerador de Conteúdo' },
  },
  // Relatórios
  {
    path: '/reports/campaigns',
    component: () => import('@/pages/reports/Campaigns.vue'),
    meta: { title: 'Relatórios' },
  },
  {
    path: '/reports/campaigns/:id',
    component: () => import('@/pages/reports/CampaignDetail.vue'),
    meta: { title: 'Detalhe da Campanha' },
  },
  {
    path: '/reports/credits',
    component: () => import('@/pages/reports/Credits.vue'),
    meta: { title: 'Extrato de Créditos' },
  },
  {
    path: '/admin/settings/whatsapp',
    component: () => import('@/pages/admin/SettingsWhatsApp.vue'),
    meta: { superadmin: true, title: 'Settings • WhatsApp' },
  },
  {
    path: '/conversations',
    component: () => import('@/pages/conversations/Index.vue'),
    meta: { title: 'Conversas' },
  },
  {
    path: '/chatbot/settings',
    component: () => import('@/pages/chatbot/Settings.vue'),
    meta: { title: 'Chatbot' },
  },
  {
    path: '/admin/ai-personas',
    component: () => import('@/pages/admin/AiPersonas.vue'),
    meta: { superadmin: true, title: 'Admin • Personas IA' },
  },
  {
    path: '/funnels',
    component: () => import('@/pages/funnels/Index.vue'),
    meta: { title: 'Funis' },
  },
  {
    path: '/funnels/create',
    component: () => import('@/pages/funnels/Editor.vue'),
    meta: { title: 'Novo Funil' },
  },
  {
    path: '/funnels/:id/edit',
    component: () => import('@/pages/funnels/Editor.vue'),
    meta: { title: 'Editar Funil' },
  },
  {
    path: '/admin/infobip-whatsapp',
    component: () => import('@/pages/admin/InfobipWhatsApp.vue'),
    meta: { superadmin: true, title: 'Admin • WhatsApp Infobip' },
  },
  {
    path: '/admin/infobip-email',
    component: () => import('@/pages/admin/InfobipEmailDomains.vue'),
    meta: { superadmin: true, title: 'Admin • Domínios de Email Infobip' },
  },
  {
    path: '/profile',
    component: () => import('@/pages/profile/Index.vue'),
    meta: { title: 'Meu Perfil' },
  },
  {
    path: '/auth/forgot-password',
    component: () => import('@/pages/auth/ForgotPassword.vue'),
    meta: { public: true, title: 'Esqueceu a senha' },
  },
  {
    path: '/auth/reset-password',
    component: () => import('@/pages/auth/ResetPassword.vue'),
    meta: { public: true, title: 'Redefinir senha' },
  },
  {
    path: '/terms',
    component: () => import('@/pages/legal/Terms.vue'),
    meta: { public: true, title: 'Termos de Uso' },
  },
  {
    path: '/privacy',
    component: () => import('@/pages/legal/Privacy.vue'),
    meta: { public: true, title: 'Política de Privacidade' },
  },
  {
    path: '/settings/plans',
    component: () => import('@/pages/settings/Plans.vue'),
    meta: { title: 'Planos' },
  },
  {
    path: '/settings/whatsapp',
    component: () => import('@/pages/settings/WhatsApp.vue'),
    meta: { title: 'Configuracoes WhatsApp' },
  },
  {
    path: '/settings/api-tokens',
    component: () => import('@/pages/settings/ApiTokens.vue'),
    meta: { title: 'Tokens de API' },
  },
  {
    path: '/settings/webhooks',
    component: () => import('@/pages/settings/Webhooks.vue'),
    meta: { title: 'Webhooks' },
  },
  {
    path: '/settings/email-domains',
    component: () => import('@/pages/settings/EmailDomains.vue'),
    meta: { title: 'Dominios de Email' },
  },
  {
    path: '/settings/opt-outs',
    component: () => import('@/pages/settings/OptOuts.vue'),
    meta: { title: 'Opt-outs' },
  },
  {
    path: '/settings/audit-log',
    component: () => import('@/pages/settings/AuditLog.vue'),
    meta: { title: 'Histórico de Auditoria' },
  },
  {
    path: '/plans',
    component: () => import('@/pages/checkout/PricingPlans.vue'),
    meta: { public: true, title: 'Planos' },
  },
  {
    path: '/plans/checkout/:planSlug',
    component: () => import('@/pages/checkout/CheckoutPage.vue'),
    meta: { title: 'Checkout' },
  },
  {
    path: '/settings/checkout/:planSlug',
    component: () => import('@/pages/checkout/CheckoutPage.vue'),
    meta: { title: 'Checkout' },
  },
  {
    path: '/settings/saldo',
    component: () => import('@/pages/settings/Saldo.vue'),
    meta: { title: 'Meu Saldo' },
  },
  // Legacy redirect — keep for ~30 days after launch then remove.
  {
    path: '/settings/credits',
    redirect: '/settings/saldo',
  },
  {
    path: '/checkout/thank-you',
    component: () => import('@/pages/checkout/CheckoutThankYou.vue'),
    meta: { title: 'Assinatura Confirmada' },
  },
  {
    path: '/:pathMatch(.*)*',
    component: () => import('@/pages/NotFound.vue'),
    meta: { title: 'Página não encontrada' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.onError((error, to) => {
  const message = (error as Error)?.message ?? ''
  const isChunkLoadError =
    /Failed to fetch dynamically imported module/i.test(message) ||
    /error loading dynamically imported module/i.test(message) ||
    /Loading chunk \S+ failed/i.test(message) ||
    /Importing a module script failed/i.test(message)

  if (!isChunkLoadError) return

  // Guard against reload loops if the new build is also broken.
  const RELOAD_FLAG = 'bc:chunkReloadAt'
  const last = Number(sessionStorage.getItem(RELOAD_FLAG) ?? 0)
  if (Date.now() - last < 10_000) return
  sessionStorage.setItem(RELOAD_FLAG, String(Date.now()))

  window.location.assign(to?.fullPath ?? window.location.pathname)
})

router.beforeEach((to, _from, next) => {
  const auth = useAuthStore()
  if (!to.meta.public && !auth.isAuthenticated) {
    next({ path: '/login', query: { redirect: to.fullPath } })
  } else if (
    auth.user?.force_password_reset &&
    to.path !== '/profile' &&
    !to.path.startsWith('/auth/')
  ) {
    next({ path: '/profile', query: { force_password_reset: '1' } })
  } else if (to.meta.superadmin && auth.user?.role !== 'superadmin') {
    next('/dashboard')
  } else if (to.meta.role) {
    // meta.role: string | string[] — allow if user role matches, or user is superadmin
    const required = Array.isArray(to.meta.role) ? to.meta.role : [to.meta.role as string]
    const userRole = auth.user?.role ?? ''
    if (userRole === 'superadmin' || required.includes(userRole)) {
      next()
    } else {
      next('/dashboard')
    }
  } else if (to.path === '/login' && auth.isAuthenticated) {
    const redirect = to.query.redirect as string
    // Only allow internal redirects (starts with / and not //)
    const safeRedirect = redirect && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/dashboard'
    next(safeRedirect)
  } else {
    next()
  }
})

export default router
