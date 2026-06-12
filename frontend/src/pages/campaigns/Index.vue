<template>
  <div>
    <!-- Inline header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Campanhas</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">Gerencie e acompanhe seus envios</span>
      </div>
      <button class="btn btn-primary" style="border-radius:8px" @click="router.push('/campaigns/new')">
        <i class="ti ti-plus me-1"></i> Nova campanha
      </button>
    </div>

    <div class="card" style="border-radius:14px">
      <!-- FilterBar -->
      <div class="card-body pb-0">
        <FilterBar
          :search="filters.search"
          @update:search="filters.search = $event"
          search-placeholder="Buscar campanhas..."
        >
          <select class="form-select" v-model="filters.type" @change="load(1)">
            <option value="">Todos os canais</option>
            <option value="sms">SMS</option>
            <option value="voice">Voz</option>
            <option value="email">Email</option>
            <option value="whatsapp">WhatsApp</option>
          </select>
          <select class="form-select" v-model="filters.status" @change="load(1)">
            <option value="">Todos os status</option>
            <option value="draft">Rascunho</option>
            <option value="scheduled">Agendada</option>
            <option value="processing">Processando</option>
            <option value="running">Em execução</option>
            <option value="completed">Concluída</option>
            <option value="failed">Falhou</option>
          </select>
        </FilterBar>
      </div>

      <div class="card-body p-0">
        <!-- Loading skeleton -->
        <TableSkeleton v-if="isLoading" :rows="6" :cols="7" />

        <!-- Empty state -->
        <EmptyState
          v-else-if="!items.length"
          icon="ti-speakerphone"
          title="Nenhuma campanha ainda"
          description="Crie a primeira campanha para começar a enviar."
        >
          <button class="btn btn-primary" style="border-radius:8px" @click="router.push('/campaigns/new')">
            <i class="ti ti-plus me-1"></i> Criar campanha
          </button>
        </EmptyState>

        <!-- Table -->
        <div v-else class="table-responsive">
          <table class="table card-table table-vcenter table-clickable">
            <thead>
              <tr>
                <th>Nome</th>
                <th>Canal</th>
                <th>Status</th>
                <th>Contatos</th>
                <th>Taxa entrega</th>
                <th>Criada em</th>
                <th class="w-1"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in items" :key="c.id" @click="router.push('/campaigns/' + c.id)">
                <td class="fw-bold">{{ c.name }}</td>
                <td>
                  <StatusBadge :label="channelMap[c.type]?.label ?? c.type" :status="c.type" />
                </td>
                <td>
                  <StatusBadge :label="statusMap[c.status]?.label ?? c.status" :status="c.status" dot />
                </td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">{{ c.contact_list?.contact_count ?? '—' }}</td>
                <td :class="rateColor(c)" style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">{{ deliveryRate(c) }}</td>
                <td class="text-muted">{{ fmtDate(c.created_at) }}</td>
                <td class="text-end" @click.stop>
                  <div class="dropdown">
                    <button class="btn btn-ghost-secondary btn-sm dropdown-toggle align-text-top" data-bs-toggle="dropdown">
                      Ações
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                      <a class="dropdown-item" href="#" @click.prevent="router.push('/campaigns/' + c.id)">
                        <i class="ti ti-eye me-2"></i>Ver detalhes
                      </a>
                      <a v-if="c.status === 'draft'" class="dropdown-item" href="#" @click.prevent="router.push('/campaigns/create?edit=' + c.id)">
                        <i class="ti ti-pencil me-2"></i>Editar
                      </a>
                      <a class="dropdown-item" href="#" @click.prevent="duplicateCampaign(c)">
                        <i class="ti ti-copy me-2"></i>Duplicar
                      </a>
                      <a v-if="['draft','scheduled'].includes(c.status)" class="dropdown-item" href="#" @click.prevent="confirmTarget = c; confirmOpen = true">
                        <i class="ti ti-send me-2"></i>Enviar agora
                      </a>
                      <div class="dropdown-divider"></div>
                      <a v-if="c.status === 'draft'" class="dropdown-item text-danger" href="#" @click.prevent="deleteTarget = c">
                        <i class="ti ti-trash me-2"></i>Deletar
                      </a>
                    </div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination footer -->
      <div v-if="!isLoading && items.length && pagination.last_page > 1" class="card-footer d-flex align-items-center">
        <p class="m-0 text-muted" style="font-size:0.82rem">
          Mostrando <span style="font-family:'JetBrains Mono',monospace">{{ paginationFrom }}–{{ paginationTo }}</span> de <span style="font-family:'JetBrains Mono',monospace">{{ pagination.total }}</span> campanhas
        </p>
        <nav aria-label="Paginação" class="ms-auto">
          <ul class="pagination m-0">
            <li class="page-item" :class="{ disabled: pagination.current_page <= 1 }">
              <button class="page-link" @click="load(pagination.current_page - 1)" aria-label="Página anterior">
                <i class="ti ti-chevron-left"></i>
              </button>
            </li>
            <li v-for="p in pageNumbers" :key="p" class="page-item" :class="{ active: p === pagination.current_page }">
              <button class="page-link" @click="load(p)" :aria-current="p === pagination.current_page ? 'page' : undefined">{{ p }}</button>
            </li>
            <li class="page-item" :class="{ disabled: pagination.current_page >= pagination.last_page }">
              <button class="page-link" @click="load(pagination.current_page + 1)" aria-label="Próxima página">
                <i class="ti ti-chevron-right"></i>
              </button>
            </li>
          </ul>
        </nav>
      </div>
    </div>
  </div>

  <ConfirmSendModal
    v-if="confirmTarget"
    :visible="confirmOpen"
    :campaign="{ ...confirmTarget, estimated_contacts: confirmTarget.estimated_contacts ?? confirmTarget.contact_list?.contact_count ?? 0 }"
    :credits-balance="(auth.user?.tenant as any)?.balance_cents ?? 0"
    :is-sending="isSending"
    @confirmed="doSendNow"
    @cancelled="confirmOpen = false; confirmTarget = null"
  />

  <ConfirmModal
    :visible="!!deleteTarget"
    title="Excluir campanha"
    :message="'Excluir a campanha ' + (deleteTarget?.name ?? '') + '?'"
    confirm-text="Excluir"
    :loading="isDeleting"
    @confirm="doDelete"
    @cancel="deleteTarget = null"
  />
</template>

<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useApi } from '@/composables/useApi'
import { useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import ConfirmSendModal from '@/components/campaigns/ConfirmSendModal.vue'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import FilterBar from '@/components/ui/FilterBar.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'

type Campaign = {
  id: number; name: string; type: 'sms'|'voice'|'email'; status: string;
  content?: string; subject?: string; estimated_contacts?: number;
  created_at?: string; sent_count?: number; failed_count?: number;
  contact_list?: { id: number; name: string; contact_count: number }
}

const { get, post, del } = useApi()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const statusMap: Record<string, { label: string; class: string }> = {
  draft:      { label: 'Rascunho',    class: 'bg-warning text-dark' },
  scheduled:  { label: 'Agendada',    class: 'bg-info' },
  processing: { label: 'Processando', class: 'bg-azure' },
  running:    { label: 'Em execução', class: 'bg-azure' },
  completed:  { label: 'Concluída',   class: 'bg-success' },
  failed:     { label: 'Falhou',      class: 'bg-danger' },
}

const channelMap: Record<string, { label: string; class: string }> = {
  sms:      { label: 'SMS',      class: 'bg-green' },
  voice:    { label: 'Voz',      class: 'bg-pink' },
  email:    { label: 'Email',    class: 'bg-blue' },
  whatsapp: { label: 'WhatsApp', class: 'bg-green' },
}

const items = ref<Campaign[]>([])
const isLoading = ref(false)
const filters = ref({ search: '', type: '', status: '' })
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 })

// Confirm send modal state
const confirmOpen = ref(false)
const confirmTarget = ref<Campaign | null>(null)
const isSending = ref(false)

// Pagination helpers
const paginationFrom = computed(() => {
  return (pagination.value.current_page - 1) * pagination.value.per_page + 1
})
const paginationTo = computed(() => {
  return Math.min(pagination.value.current_page * pagination.value.per_page, pagination.value.total)
})
const pageNumbers = computed(() => {
  const pages: number[] = []
  for (let i = 1; i <= pagination.value.last_page; i++) {
    pages.push(i)
  }
  return pages
})

async function load(page = 1) {
  isLoading.value = true
  try {
    const res = await get<any>('/campaigns', {
      page,
      search: filters.value.search || undefined,
      type: filters.value.type || undefined,
      status: filters.value.status || undefined,
    })
    items.value = res?.data ?? res ?? []
    if (res?.meta) pagination.value = res.meta
  } finally {
    isLoading.value = false
  }
}

// Debounce search
let searchTimer: any
watch(() => filters.value.search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => load(1), 300)
})

// Delivery rate helpers
function deliveryRate(c: Campaign): string {
  const total = c.estimated_contacts ?? c.contact_list?.contact_count ?? 0
  if (!total || !c.sent_count) return '—'
  return Math.round((c.sent_count / total) * 100) + '%'
}
function rateColor(c: Campaign): string {
  const total = c.estimated_contacts ?? c.contact_list?.contact_count ?? 0
  if (!total || !c.sent_count) return ''
  const pct = (c.sent_count / total) * 100
  if (pct >= 80) return 'text-success'
  if (pct >= 50) return 'text-warning'
  return 'text-danger'
}

// Date format helper
function fmtDate(d?: string) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })
}

// Actions
async function duplicateCampaign(c: Campaign) {
  try {
    const res = await post<any>('/campaigns', {
      name: c.name + ' (cópia)',
      type: c.type,
      content: c.content,
      subject: c.subject,
      audio_url: (c as any).audio_url,
      contact_list_id: c.contact_list?.id,
    })
    toast.success('Campanha duplicada')
    load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao duplicar')
  }
}

const deleteTarget = ref<Campaign | null>(null)
const isDeleting = ref(false)

async function doDelete() {
  if (!deleteTarget.value) return
  isDeleting.value = true
  try {
    await del('/campaigns/' + deleteTarget.value.id)
    toast.success('Campanha excluída')
    deleteTarget.value = null
    load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao excluir')
  } finally {
    isDeleting.value = false
  }
}

async function doSendNow() {
  if (!confirmTarget.value) return
  isSending.value = true
  try {
    await post(`/campaigns/${confirmTarget.value.id}/send-now`)
    toast.success('Disparo iniciado')
    confirmOpen.value = false
    confirmTarget.value = null
    await load(pagination.value.current_page)
  } catch (e: any) {
    const data = e?.response?.data
    const errors = Array.isArray(data?.errors) ? data.errors.join('. ') : null
    toast.error(errors ?? data?.message ?? 'Erro ao disparar')
  } finally {
    isSending.value = false
  }
}

onMounted(() => load())
</script>

<style scoped>
.table-clickable tbody tr {
  cursor: pointer;
  transition: background 0.15s;
}
.table-clickable tbody tr:hover {
  background: var(--bc-gray-soft);
}
.table-clickable td {
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
}
</style>
