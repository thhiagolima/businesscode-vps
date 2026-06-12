<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <button class="btn btn-ghost-secondary btn-sm mb-2" @click="$router.push('/admin/tenants')">
          <i class="ti ti-chevron-left me-1"></i>Voltar
        </button>
        <h2 style="font-size:1.4rem;font-weight:700;margin:0">
          {{ tenant?.name || 'Tenant' }}
          <span v-if="tenant" class="badge bg-secondary-lt" style="font-size:0.7rem;vertical-align:middle">{{ tenant.status }}</span>
        </h2>
        <span v-if="tenant" style="font-size:0.78rem;color:var(--bc-text-muted);font-family:'JetBrains Mono',monospace">
          #{{ tenant.id }} · {{ tenant.slug }}
        </span>
      </div>
      <button class="btn btn-ghost-secondary" :disabled="loading" @click="reload">
        <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
        <i v-else class="ti ti-refresh me-1"></i>Atualizar
      </button>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
      <li v-for="t in tabs" :key="t.id" class="nav-item">
        <a href="#" class="nav-link" :class="{ active: active === t.id }" @click.prevent="go(t.id)">
          <i :class="`ti ${t.icon} me-1`"></i>{{ t.label }}
        </a>
      </li>
    </ul>

    <TabOverview        v-if="active === 'overview'"        :tenant-id="tenantId" />
    <TabCampaigns       v-else-if="active === 'campaigns'"  :tenant-id="tenantId" />
    <TabContacts        v-else-if="active === 'contacts'"   :tenant-id="tenantId" />
    <TabConversations   v-else-if="active === 'conversations'" :tenant-id="tenantId" />
    <TabFunnels         v-else-if="active === 'funnels'"    :tenant-id="tenantId" />
    <TabUsers           v-else-if="active === 'users'"      :tenant-id="tenantId" />
    <TabChannels        v-else-if="active === 'channels'"   :tenant-id="tenantId" />
    <TabApiTokens       v-else-if="active === 'api-tokens'" :tenant-id="tenantId" />
    <TabReports         v-else-if="active === 'reports'"    :tenant-id="tenantId" />
    <TabAudit           v-else-if="active === 'audit'"      :tenant-id="tenantId" />
    <div v-else-if="active === 'billing'" class="card" style="border-radius:14px">
      <div class="card-body text-center py-5">
        <i class="ti ti-cash" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
        <h3 style="font-size:1rem;margin-top:1rem">Financeiro</h3>
        <p style="font-size:0.85rem;color:var(--bc-text-muted)">A área financeira tem sua própria página.</p>
        <button class="btn btn-primary" @click="$router.push(`/admin/billing/tenants/${tenantId}`)">
          <i class="ti ti-external-link me-1"></i>Abrir financeiro
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAdminTenantDetailStore } from '@/stores/adminTenantDetail'
import TabOverview from '@/components/admin/tenants/TabOverview.vue'
import TabCampaigns from '@/components/admin/tenants/TabCampaigns.vue'
import TabContacts from '@/components/admin/tenants/TabContacts.vue'
import TabConversations from '@/components/admin/tenants/TabConversations.vue'
import TabFunnels from '@/components/admin/tenants/TabFunnels.vue'
import TabUsers from '@/components/admin/tenants/TabUsers.vue'
import TabChannels from '@/components/admin/tenants/TabChannels.vue'
import TabApiTokens from '@/components/admin/tenants/TabApiTokens.vue'
import TabReports from '@/components/admin/tenants/TabReports.vue'
import TabAudit from '@/components/admin/tenants/TabAudit.vue'

const route = useRoute()
const router = useRouter()
const store = useAdminTenantDetailStore()
const { tenant, loading } = storeToRefs(store)

const tenantId = computed(() => Number(route.params.id))
const active = computed(() => (route.query.tab as string) || 'overview')

const tabs = [
  { id: 'overview',       label: 'Visão geral',   icon: 'ti-layout-dashboard' },
  { id: 'campaigns',      label: 'Campanhas',     icon: 'ti-bullhorn' },
  { id: 'contacts',       label: 'Contatos',      icon: 'ti-address-book' },
  { id: 'conversations',  label: 'Conversas',     icon: 'ti-messages' },
  { id: 'funnels',        label: 'Funis',         icon: 'ti-route' },
  { id: 'users',          label: 'Usuários',      icon: 'ti-users' },
  { id: 'channels',       label: 'Canais',        icon: 'ti-broadcast' },
  { id: 'api-tokens',     label: 'API Tokens',    icon: 'ti-key' },
  { id: 'reports',        label: 'Relatórios',    icon: 'ti-chart-bar' },
  { id: 'audit',          label: 'Auditoria',     icon: 'ti-history' },
  { id: 'billing',        label: 'Financeiro',    icon: 'ti-cash' },
]

function go(tab: string) {
  router.replace({ query: { ...route.query, tab } })
}

async function reload() {
  await store.loadOverview(tenantId.value)
}

onMounted(reload)
watch(tenantId, reload)
</script>
