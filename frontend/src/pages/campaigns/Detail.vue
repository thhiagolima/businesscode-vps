<template>
  <div>
    <!-- Inline header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">
          {{ campaign?.name ?? 'Detalhes da Campanha' }}
        </h2>
        <StatusBadge v-if="campaign" :label="campaign.type.toUpperCase()" :status="campaign.type" />
        <StatusBadge v-if="campaign" :label="statusMap[campaign.status] ?? campaign.status" :status="campaign.status" dot />
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button v-if="campaign?.status==='draft'" class="btn btn-primary" style="border-radius:8px" @click="confirmOpen = true"><i class="ti ti-send me-1"></i>Enviar agora</button>
        <button v-if="campaign?.status==='draft'" class="btn btn-ghost-secondary" style="border-radius:8px" @click="router.push('/campaigns/create?edit=' + campaign.id)"><i class="ti ti-pencil me-1"></i>Editar</button>
        <button v-if="campaign?.status==='draft'" class="btn btn-ghost-danger" style="border-radius:8px" @click="showDeleteConfirm = true"><i class="ti ti-trash me-1"></i>Excluir</button>
        <button v-if="campaign?.status==='draft' || campaign?.status==='scheduled'" class="btn btn-outline-secondary" style="border-radius:8px" @click="duplicateCampaign"><i class="ti ti-copy me-1"></i>Duplicar</button>
        <button v-if="campaign?.status==='scheduled'" class="btn btn-outline-danger" style="border-radius:8px"
                :disabled="isCancelling" @click="showCancelConfirm = true">
          <span v-if="isCancelling" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-x me-1"></i>Cancelar
        </button>
        <button v-if="campaign?.status==='completed'" class="btn btn-primary" style="border-radius:8px" @click="router.push(`/reports/campaigns/${campaign.id}`)"><i class="ti ti-chart-bar me-1"></i>Ver relatório</button>
        <button v-if="campaign?.status==='failed'" class="btn btn-outline-warning" style="border-radius:8px"
                :disabled="isResetting" @click="showResetConfirm = true">
          <span v-if="isResetting" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-refresh me-1"></i>Redefinir
        </button>
      </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
      <div class="col-sm-6 col-lg-3">
        <KpiCard label="Total" :value="displayKpi(0)" icon="ti-users" color="primary" />
      </div>
      <div class="col-sm-6 col-lg-3">
        <KpiCard label="Enviados" :value="displayKpi(1)" icon="ti-send" color="info" />
      </div>
      <div class="col-sm-6 col-lg-3">
        <KpiCard label="Entregues" :value="displayKpi(2)" icon="ti-circle-check" color="success" />
      </div>
      <div class="col-sm-6 col-lg-3">
        <KpiCard label="Falhas" :value="displayKpi(3)" icon="ti-alert-triangle" color="danger" />
      </div>
    </div>

    <!-- Card with tabs -->
    <div class="card" style="border-radius:14px">
      <div class="card-header" style="border-bottom:1px solid var(--bc-outline)"  >
        <ul class="nav nav-tabs card-header-tabs">
          <li class="nav-item">
            <a href="#" class="nav-link" :class="{active: activeTab==='overview'}" @click.prevent="activeTab='overview'">
              <i class="ti ti-layout-dashboard me-1"></i>Visão Geral
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link" :class="{active: activeTab==='dispatches'}" @click.prevent="activeTab='dispatches'">
              <i class="ti ti-send me-1"></i>Envios
            </a>
          </li>
        </ul>
      </div>

      <div class="card-body">
        <!-- Overview tab -->
        <div v-if="activeTab==='overview'">
          <!-- Progress -->
          <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span style="font-size:0.82rem;font-weight:600;color:var(--bc-text-muted)">Progresso de entrega</span>
              <span style="font-family:'JetBrains Mono',monospace;font-size:0.85rem;font-weight:600">{{ progressPct }}%</span>
            </div>
            <div class="bc-progress">
              <div class="bc-progress-bar" :style="{ width: progressPct + '%' }"></div>
            </div>
          </div>

          <!-- Campaign info -->
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <div class="bc-info-list">
                <div class="bc-info-item">
                  <span class="bc-info-label">Canal</span>
                  <StatusBadge v-if="campaign" :label="campaign.type.toUpperCase()" :status="campaign.type" />
                </div>
                <div class="bc-info-item">
                  <span class="bc-info-label">Audiência</span>
                  <span class="bc-info-value">{{ audienceLabel }}</span>
                </div>
                <div class="bc-info-item">
                  <span class="bc-info-label">Agendamento</span>
                  <span class="bc-info-value">{{ campaign?.scheduled_at ? formatDate(campaign.scheduled_at) : '—' }}</span>
                </div>
                <div class="bc-info-item">
                  <span class="bc-info-label">Início</span>
                  <span class="bc-info-value">{{ campaign?.started_at ? formatDate(campaign.started_at) : '—' }}</span>
                </div>
                <div class="bc-info-item">
                  <span class="bc-info-label">Conclusão</span>
                  <span class="bc-info-value">{{ campaign?.completed_at ? formatDate(campaign.completed_at) : '—' }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Dispatches tab -->
        <div v-else>
          <FilterBar
            :searchable="false"
          >
            <select class="form-select" v-model="filterStatus" @change="loadDispatches(1)">
              <option value="">Todos os status</option>
              <option value="queued">Queued</option>
              <option value="sent">Sent</option>
              <option value="delivered">Delivered</option>
              <option value="failed">Failed</option>
            </select>
          </FilterBar>

          <TableSkeleton v-if="dispatchesLoading" :rows="5" :cols="6" />

          <div v-else class="table-responsive">
            <table class="table card-table table-vcenter">
              <thead>
                <tr>
                  <th>Contato</th>
                  <th>Telefone</th>
                  <th>Status</th>
                  <th>Enviado em</th>
                  <th>Entregue em</th>
                  <th>Erro</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in dispatches" :key="d.id">
                  <td>{{ d.contact_name ?? '—' }}</td>
                  <td style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">{{ d.contact_phone ?? '—' }}</td>
                  <td><StatusBadge :label="d.status" :status="d.status" dot /></td>
                  <td class="text-muted">{{ d.sent_at ? formatDate(d.sent_at) : '—' }}</td>
                  <td class="text-muted">{{ d.delivered_at ? formatDate(d.delivered_at) : '—' }}</td>
                  <td class="text-muted" style="font-size:0.82rem">{{ d.error ?? '—' }}</td>
                </tr>
                <tr v-if="!dispatches.length">
                  <td colspan="6" class="p-0 border-0">
                    <EmptyState
                      icon="ti-send"
                      title="Nenhum envio registrado"
                      description="Os envios aparecerão aqui assim que a campanha for disparada."
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div class="d-flex align-items-center justify-content-between mt-3">
            <span class="text-muted" style="font-size:0.82rem">Página <span style="font-family:'JetBrains Mono',monospace">{{ page }}</span></span>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px" :disabled="page<=1" @click="loadDispatches(page-1)">
                <i class="ti ti-chevron-left"></i>
              </button>
              <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px" :disabled="page >= lastPage" @click="loadDispatches(page+1)">
                <i class="ti ti-chevron-right"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <ConfirmModal
    :visible="showDeleteConfirm"
    title="Excluir campanha"
    message="Tem certeza? Esta ação não pode ser desfeita."
    confirm-text="Excluir"
    :loading="isDeleting"
    @confirm="deleteCampaign"
    @cancel="showDeleteConfirm = false"
  />

  <ConfirmModal
    :visible="showCancelConfirm"
    title="Cancelar agendamento"
    message="Confirmar o cancelamento? A campanha voltará para rascunho."
    confirm-text="Cancelar agendamento"
    :loading="isCancelling"
    @confirm="cancelCampaign"
    @cancel="showCancelConfirm = false"
  />

  <ConfirmModal
    :visible="showResetConfirm"
    title="Redefinir campanha"
    message="Redefinir voltará a campanha para rascunho e zera os contadores. Você poderá editar e disparar novamente."
    confirm-text="Redefinir"
    :loading="isResetting"
    @confirm="resetCampaign"
    @cancel="showResetConfirm = false"
  />

  <ConfirmSendModal
    v-if="campaign"
    :visible="confirmOpen"
    :campaign="{ ...campaign, estimated_contacts: kpis[0]?.value ?? 0 }"
    :credits-balance="(auth.user?.tenant as any)?.balance_cents ?? 0"
    :is-sending="isSending"
    @confirmed="doSendNow"
    @cancelled="confirmOpen = false"
  />
</template>

<script setup lang="ts">
import { onMounted, ref, computed, watch, onBeforeUnmount } from 'vue'
import { useApi } from '@/composables/useApi'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import ConfirmSendModal from '@/components/campaigns/ConfirmSendModal.vue'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import KpiCard from '@/components/ui/KpiCard.vue'
import FilterBar from '@/components/ui/FilterBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const { get, post, del } = useApi()
const toast = useToast()
const auth = useAuthStore()

const confirmOpen = ref(false)
const isSending = ref(false)
const showDeleteConfirm = ref(false)
const isDeleting = ref(false)
const showCancelConfirm = ref(false)
const isCancelling = ref(false)
const showResetConfirm = ref(false)
const isResetting = ref(false)

async function cancelCampaign() {
  if (!campaign.value) return
  isCancelling.value = true
  try {
    await post(`/campaigns/${campaign.value.id}/cancel`)
    toast.success('Agendamento cancelado')
    showCancelConfirm.value = false
    await loadCampaign()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao cancelar')
  } finally {
    isCancelling.value = false
  }
}

async function resetCampaign() {
  if (!campaign.value) return
  isResetting.value = true
  try {
    await post(`/campaigns/${campaign.value.id}/reset`)
    toast.success('Campanha redefinida para rascunho')
    showResetConfirm.value = false
    await loadCampaign()
    await loadKpis()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao redefinir')
  } finally {
    isResetting.value = false
  }
}

const statusMap: Record<string, string> = {
  draft: 'Rascunho',
  processing: 'Processando',
  running: 'Em execução',
  completed: 'Concluída',
  failed: 'Falhou',
  scheduled: 'Agendada',
}

async function doSendNow() {
  if (!campaign.value) return
  isSending.value = true
  try {
    await post(`/campaigns/${campaign.value.id}/send-now`)
    toast.success('Disparo iniciado')
    confirmOpen.value = false
    await loadCampaign()
    await loadKpis()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao disparar')
  } finally {
    isSending.value = false
  }
}

async function deleteCampaign() {
  if (!campaign.value) return
  isDeleting.value = true
  try {
    await del(`/campaigns/${campaign.value.id}`)
    toast.success('Campanha excluída')
    router.push('/campaigns')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao excluir')
  } finally {
    isDeleting.value = false
  }
}

async function duplicateCampaign() {
  if (!campaign.value) return
  try {
    const res = await post<any>('/campaigns', {
      name: campaign.value.name + ' (cópia)',
      type: campaign.value.type,
      content: campaign.value.content,
      subject: campaign.value.subject,
      audio_url: campaign.value.audio_url,
      contact_list_id: campaign.value.contact_list_id,
    })
    toast.success('Campanha duplicada')
    router.push('/campaigns/' + (res?.id ?? ''))
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao duplicar')
  }
}

type Campaign = { id: number; name: string; type: string; status: string; scheduled_at?: string; started_at?: string; completed_at?: string; contact_list_id?: number; contact_list?: { id: number; name: string; contact_count?: number } | null; settings?: { adhoc_phones?: string[] } | null; content?: string; subject?: string; audio_url?: string }
const campaign = ref<Campaign | null>(null)

// Audiência legível: nome da lista, ou "Números digitados (N)" para ad-hoc,
// nunca o ID cru da lista (que não diz nada ao usuário).
const audienceLabel = computed(() => {
  const c = campaign.value
  if (!c) return '—'
  if (c.contact_list?.name) return c.contact_list.name
  const adhoc = c.settings?.adhoc_phones
  if (adhoc && adhoc.length) return `Números digitados (${adhoc.length})`
  return '—'
})
const activeTab = ref<'overview'|'dispatches'>('overview')

const kpis = ref([
  { title: 'Total', value: 0 },
  { title: 'Enviados', value: 0 },
  { title: 'Entregues', value: 0 },
  { title: 'Falhas', value: 0 },
])

// kpisLoaded distingue "0 real (campanha sem disparo)" de "erro/loading"
// para que não exibamos zeros como se fossem dados verificados.
const kpisLoaded = ref(false)

function displayKpi(idx: number): number | string {
  if (!kpisLoaded.value) return '—'
  return kpis.value[idx].value as number
}

const progressPct = computed(() => {
  if (!kpisLoaded.value) return 0
  const total = kpis.value[0].value as number
  const delivered = kpis.value[2].value as number
  if (!total) return 0
  return Math.min(100, Math.round((delivered / total) * 100))
})

function statusClass(s: string) {
  return {
    draft: 'bg-secondary',
    processing: 'bg-warning',
    running: 'bg-azure',
    completed: 'bg-success',
    failed: 'bg-danger',
    scheduled: 'bg-info',
  }[s] ?? 'bg-secondary'
}
function dispatchClass(s: string) {
  return {
    queued: 'bg-secondary',
    sent: 'bg-azure',
    delivered: 'bg-success',
    failed: 'bg-danger',
  }[s] ?? 'bg-secondary'
}
function formatDate(dt: string) {
  try {
    return new Date(dt).toLocaleString('pt-BR')
  } catch { return dt }
}

const dispatches = ref<any[]>([])
const page = ref(1)
const lastPage = ref(1)
const dispatchesLoading = ref(false)
const filterStatus = ref('')
let timer: any

async function loadCampaign() {
  try {
    const id = route.params.id
    const resp = await get<any>(`/campaigns/${id}`)
    campaign.value = resp ?? null
  } catch {
    campaign.value = { id: Number(route.params.id), name: '—', type: 'sms', status: 'draft' }
    toast.warning('Não foi possível carregar os detalhes da campanha.')
  }
}

async function loadKpis() {
  try {
    const id = route.params.id
    const resp = await get<any>(`/reports/campaigns/${id}`)
    const stats = resp?.stats ?? {}
    kpis.value = [
      { title: 'Total',     value: stats.total     ?? 0 },
      { title: 'Enviados',  value: stats.sent       ?? 0 },
      { title: 'Entregues', value: stats.delivered  ?? 0 },
      { title: 'Falhas',    value: stats.failed     ?? 0 },
    ]
    kpisLoaded.value = true
  } catch {
    // KPIs ficam como "—": NÃO mascarar erro de rede como "0 enviados".
    kpisLoaded.value = false
    toast.warning('Não foi possível carregar as métricas. Tente novamente em instantes.')
  }
}

async function loadDispatches(p = 1) {
  page.value = Math.max(1, p)
  dispatchesLoading.value = true
  try {
    const id = route.params.id
    const res = await get<any>(`/campaigns/${id}/dispatches`, {
      page: page.value,
      ...(filterStatus.value ? { status: filterStatus.value } : {}),
    })
    dispatches.value = (res?.data ?? res ?? []).map((d: any) => ({
      ...d,
      contact_name:  d.contact?.name  ?? null,
      contact_phone: d.phone          ?? null,
      error:         d.error_message  ?? null,
    }))
    lastPage.value = res?.meta?.last_page ?? res?.last_page ?? 1
  } catch {
    dispatches.value = []
  } finally {
    dispatchesLoading.value = false
  }
}

function startPolling() {
  stopPolling()
  timer = setInterval(async () => {
    if (campaign.value?.status !== 'running') {
      stopPolling()
      return
    }
    await loadDispatches(page.value)
    await loadKpis()
  }, 10000)
}
function stopPolling() {
  if (timer) clearInterval(timer)
}

watch(activeTab, (t) => {
  if (t === 'dispatches') {
    // Carrega os envios imediatamente ao abrir a aba — independente do status.
    // (Antes só o polling carregava, e ele só roda em 'running'; campanhas
    //  concluídas ficavam com "Nenhum envio registrado" mesmo tendo envios.)
    loadDispatches(page.value)
    startPolling()
  } else {
    stopPolling()
  }
})
onBeforeUnmount(stopPolling)

onMounted(async () => {
  await loadCampaign()
  await loadKpis()
})
</script>

<style scoped>
.bc-progress {
  height: 6px;
  background: var(--bc-gray-soft);
  border-radius: 3px;
  overflow: hidden;
}
.bc-progress-bar {
  height: 100%;
  background: var(--bc-primary, #0064ff);
  border-radius: 3px;
  transition: width 0.4s ease;
}
.bc-info-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.bc-info-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--bc-outline);
}
.bc-info-item:last-child {
  border-bottom: none;
}
.bc-info-label {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--bc-text-muted, #6c7293);
}
.bc-info-value {
  font-size: 0.85rem;
  color: var(--bc-text);
}
.nav-tabs .nav-link {
  font-size: 0.85rem;
  font-weight: 600;
}
</style>
