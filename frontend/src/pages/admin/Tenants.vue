<template>
  <div>
    <!-- Inline header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Tenants</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">Gerencie os tenants da plataforma</span>
      </div>
      <button class="btn btn-primary" style="border-radius:8px" @click="openCreate">
        <i class="ti ti-plus me-1"></i>Novo tenant
      </button>
    </div>

    <div class="card" style="border-radius:14px">
      <!-- FilterBar -->
      <div class="card-body pb-0">
        <FilterBar
          :search="filters.search"
          @update:search="filters.search = $event; applyFilters()"
          search-placeholder="Buscar por nome ou slug..."
        >
          <select class="form-select" v-model="filters.status" @change="applyFilters">
            <option value="">Todos os status</option>
            <option value="active">Ativo</option>
            <option value="trial">Trial</option>
            <option value="suspended">Suspenso</option>
          </select>
        </FilterBar>
      </div>

      <!-- Loading -->
      <TableSkeleton v-if="isLoading && !items.length" :rows="5" :cols="8" />

      <!-- Empty -->
      <EmptyState
        v-else-if="!items.length"
        icon="ti-building"
        title="Nenhum tenant encontrado"
        description="Crie o primeiro tenant para começar."
      />

      <!-- Table -->
      <div v-else class="table-responsive">
        <table class="table table-vcenter table-hover card-table table-clickable">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Slug</th>
              <th>Plano</th>
              <th>Saldo</th>
              <th>Status</th>
              <th>Canais</th>
              <th>Criado em</th>
              <th class="w-1"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in items" :key="t.id" style="cursor:pointer" @click="$router.push(`/admin/tenants/${t.id}`)">
              <td class="fw-medium">{{ t.name }}</td>
              <td style="font-family:'JetBrains Mono',monospace;font-size:0.82rem;color:var(--bc-text-muted)">{{ t.slug }}</td>
              <td>{{ t.plan?.name ?? '—' }}</td>
              <td style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">{{ brl(t.balance_cents ?? 0) }}</td>
              <td>
                <StatusBadge :label="statusLabel(t.status)" :status="t.status" dot />
              </td>
              <td>
                <div class="d-flex gap-1 flex-wrap">
                  <span v-for="ch in (t.enabled_channels ?? [])" :key="ch.id" class="badge" :style="channelBadgeStyle(ch.channel)" style="font-size:0.65rem;padding:0.15rem 0.4rem;border-radius:4px">
                    {{ channelShortLabel(ch.channel) }}
                  </span>
                  <span v-if="!(t.enabled_channels?.length)" style="font-size:0.72rem;color:var(--bc-text-muted);opacity:0.5">—</span>
                </div>
              </td>
              <td class="text-muted">{{ formatDate(t.created_at) }}</td>
              <td class="text-end text-nowrap">
                <div class="d-flex gap-1 justify-content-end">
                  <button class="btn btn-sm btn-icon btn-ghost-success" @click.stop="openCredits(t)" title="Adicionar saldo">
                    <i class="ti ti-coins"></i>
                  </button>
                  <button class="btn btn-sm btn-icon btn-ghost-primary" @click.stop="openEdit(t)" title="Editar">
                    <i class="ti ti-pencil"></i>
                  </button>
                  <button class="btn btn-sm btn-icon btn-ghost-danger" @click.stop="remove(t)" title="Excluir">
                    <i class="ti ti-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="meta.last_page > 1" class="card-footer d-flex align-items-center">
        <p class="m-0 text-muted" style="font-size:0.82rem">
          Mostrando <span style="font-family:'JetBrains Mono',monospace">{{ meta.from ?? 0 }}</span> a <span style="font-family:'JetBrains Mono',monospace">{{ meta.to ?? 0 }}</span> de <span style="font-family:'JetBrains Mono',monospace">{{ meta.total }}</span> registros
        </p>
        <ul class="pagination m-0 ms-auto">
          <li class="page-item" :class="{ disabled: meta.current_page <= 1 }">
            <a class="page-link" href="#" @click.prevent="goPage(meta.current_page - 1)">
              <i class="ti ti-chevron-left"></i>
            </a>
          </li>
          <li v-for="p in pageNumbers" :key="p" class="page-item" :class="{ active: p === meta.current_page }">
            <a class="page-link" href="#" @click.prevent="goPage(p)">{{ p }}</a>
          </li>
          <li class="page-item" :class="{ disabled: meta.current_page >= meta.last_page }">
            <a class="page-link" href="#" @click.prevent="goPage(meta.current_page + 1)">
              <i class="ti ti-chevron-right"></i>
            </a>
          </li>
        </ul>
      </div>
    </div>

    <!-- Modal Create / Edit Tenant -->
    <div class="modal modal-blur fade" id="tenantModal" tabindex="-1" ref="modalEl">
      <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:14px">
          <div class="modal-header">
            <h5 class="modal-title" style="font-weight:700">{{ isEditing ? 'Editar tenant' : 'Novo tenant' }}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form @submit.prevent="save">
            <div class="modal-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label required">Nome da empresa</label>
                  <input v-model="form.name" type="text" class="form-control" style="border-radius:10px" required placeholder="Ex: Acme Corp" @input="onNameInput" />
                </div>
                <div class="col-md-6">
                  <label class="form-label required">Slug</label>
                  <input v-model="form.slug" type="text" class="form-control" style="border-radius:10px" required placeholder="ex: acme-corp" />
                </div>
                <div class="col-md-6">
                  <label class="form-label required">Plano</label>
                  <select v-model="form.plan_id" class="form-select" style="border-radius:10px" required>
                    <option value="" disabled>Selecione um plano</option>
                    <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Saldo inicial (centavos)</label>
                  <input v-model.number="form.balance_cents" type="number" min="0" class="form-control" style="border-radius:10px" />
                </div>
                <div class="col-md-3">
                  <label class="form-label">Status</label>
                  <select v-model="form.status" class="form-select" style="border-radius:10px">
                    <option value="trial">Trial</option>
                    <option value="active">Ativo</option>
                    <option value="suspended">Suspenso</option>
                  </select>
                </div>

                <!-- User fields: only on create -->
                <template v-if="!isEditing">
                  <div class="col-12"><hr class="my-1"><div class="text-muted small mb-1">Usuário administrador do tenant</div></div>
                  <div class="col-md-4">
                    <label class="form-label required">Nome do usuário</label>
                    <input v-model="form.user_name" type="text" class="form-control" style="border-radius:10px" :required="!isEditing" placeholder="Nome completo" />
                  </div>
                  <div class="col-md-4">
                    <label class="form-label required">Email</label>
                    <input v-model="form.user_email" type="email" class="form-control" style="border-radius:10px" :required="!isEditing" placeholder="admin@empresa.com" />
                  </div>
                  <div class="col-md-4">
                    <label class="form-label required">Senha</label>
                    <input v-model="form.user_password" type="password" class="form-control" style="border-radius:10px" :required="!isEditing" placeholder="Senha inicial" />
                  </div>
                </template>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost-secondary" style="border-radius:8px" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" style="border-radius:8px" :disabled="isSaving">
                <span v-if="isSaving" class="spinner-border spinner-border-sm me-2"></span>
                {{ isEditing ? 'Salvar' : 'Criar' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal Add Credits -->
    <div class="modal modal-blur fade" id="creditsModal" tabindex="-1" ref="creditsModalEl">
      <div class="modal-dialog modal-sm">
        <div class="modal-content" style="border-radius:14px">
          <div class="modal-header">
            <h5 class="modal-title" style="font-weight:700">Adicionar saldo</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form @submit.prevent="addCredits">
            <div class="modal-body">
              <div class="mb-3 text-muted small">
                Tenant: <strong>{{ creditsTenant?.name }}</strong>
              </div>
              <div class="mb-3">
                <label class="form-label required">Quantidade</label>
                <input v-model.number="creditsForm.amount" type="number" min="1" class="form-control" style="border-radius:10px" required placeholder="Ex: 1000" />
              </div>
              <div class="mb-3">
                <label class="form-label">Descrição</label>
                <input v-model="creditsForm.description" type="text" class="form-control" style="border-radius:10px" placeholder="Ex: Recarga manual" />
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost-secondary" style="border-radius:8px" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success" style="border-radius:8px" :disabled="isSaving">
                <span v-if="isSaving" class="spinner-border spinner-border-sm me-2"></span>
                Adicionar
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref, computed, nextTick } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import FilterBar from '@/components/ui/FilterBar.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import { brl } from '@/utils/currency'
import { Modal } from 'bootstrap'

type Plan = { id: number; name: string }
type TenantChannelInfo = { id: number; channel: string; status: string }
type Tenant = {
  id: number
  name: string
  slug: string
  status: 'active' | 'trial' | 'suspended'
  balance_cents: number
  plan_id: number | null
  plan?: Plan | null
  enabled_channels?: TenantChannelInfo[]
  created_at?: string
}
type PaginationMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

const { get, post, put, del } = useApi()
const toast = useToast()

// List state
const items = ref<Tenant[]>([])
const meta = ref<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null })
const isLoading = ref(false)
const isSaving = ref(false)
const filters = ref({ status: '', search: '' })
const plans = ref<Plan[]>([])

// Tenant modal
const modalEl = ref<HTMLElement | null>(null)
let bsModal: any = null

const defaultForm = () => ({
  id: null as number | null,
  name: '',
  slug: '',
  plan_id: '' as number | string,
  balance_cents: 0,
  status: 'trial',
  user_name: '',
  user_email: '',
  user_password: '',
})

const form = ref(defaultForm())
const isEditing = computed(() => form.value.id !== null)

// Credits modal
const creditsModalEl = ref<HTMLElement | null>(null)
let bsCreditsModal: any = null
const creditsTenant = ref<Tenant | null>(null)
const creditsForm = ref({ amount: 0, description: '' })

// ---------- Helpers ----------

const slugify = (str: string) =>
  str
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')

const statusBadge = (s: string) =>
  ({
    trial: 'bg-warning-lt text-warning',
    active: 'bg-success-lt text-success',
    suspended: 'bg-danger-lt text-danger',
  }[s] ?? 'bg-secondary-lt')

const statusLabel = (s: string) =>
  ({
    trial: 'Trial',
    active: 'Ativo',
    suspended: 'Suspenso',
  }[s] ?? s)

const formatDate = (d?: string) => {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function channelShortLabel(ch: string): string {
  return ({ sms: 'SMS', voice: 'VOZ', email: 'EMAIL', whatsapp: 'WA' } as Record<string, string>)[ch] ?? ch
}

function channelBadgeStyle(ch: string): Record<string, string> {
  const styles: Record<string, Record<string, string>> = {
    sms: { background: 'rgba(0,100,255,0.08)', color: '#0064ff' },
    whatsapp: { background: 'rgba(37,211,102,0.08)', color: '#25d366' },
    email: { background: 'rgba(59,130,246,0.08)', color: '#3b82f6' },
    voice: { background: 'rgba(234,179,8,0.08)', color: '#d97706' },
  }
  return styles[ch] ?? { background: 'rgba(108,114,147,0.06)', color: '#6c7293' }
}

const pageNumbers = computed(() => {
  const pages: number[] = []
  const total = meta.value.last_page
  const current = meta.value.current_page
  const delta = 2
  for (let i = Math.max(1, current - delta); i <= Math.min(total, current + delta); i++) {
    pages.push(i)
  }
  return pages
})

// ---------- Data loading ----------

const load = async () => {
  isLoading.value = true
  try {
    const resp = await get<{ data: Tenant[]; meta: PaginationMeta }>('/admin/tenants', {
      status: filters.value.status || undefined,
      search: filters.value.search || undefined,
      page: meta.value.current_page,
    })
    items.value = resp.data
    meta.value = resp.meta
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Falha ao carregar tenants')
  } finally {
    isLoading.value = false
  }
}

const loadPlans = async () => {
  try {
    plans.value = await get<Plan[]>('/admin/plans')
  } catch {
    // silent — plans dropdown will just be empty
  }
}

const applyFilters = () => { meta.value.current_page = 1; load() }
const goPage = (p: number) => { if (p < 1 || p > meta.value.last_page) return; meta.value.current_page = p; load() }

// ---------- Tenant modal ----------

function getModal() {
  if (!bsModal && modalEl.value) {
    bsModal = new Modal(modalEl.value)
  }
  return bsModal
}

function onNameInput() {
  if (!isEditing.value) {
    form.value.slug = slugify(form.value.name)
  }
}

function openCreate() {
  form.value = defaultForm()
  nextTick(() => getModal()?.show())
}

function openEdit(tenant: Tenant) {
  form.value = {
    id: tenant.id,
    name: tenant.name,
    slug: tenant.slug,
    plan_id: tenant.plan_id ?? tenant.plan?.id ?? '',
    balance_cents: Number(tenant.balance_cents) || 0,
    status: tenant.status,
    user_name: '',
    user_email: '',
    user_password: '',
  }
  nextTick(() => getModal()?.show())
}

async function save() {
  isSaving.value = true
  try {
    if (isEditing.value) {
      const payload = {
        name: form.value.name,
        slug: form.value.slug,
        plan_id: form.value.plan_id || undefined,
        balance_cents: form.value.balance_cents,
        status: form.value.status,
      }
      await put(`/admin/tenants/${form.value.id}`, payload)
      toast.success('Tenant atualizado com sucesso')
    } else {
      const payload = {
        name: form.value.name,
        slug: form.value.slug,
        plan_id: form.value.plan_id || undefined,
        balance_cents: form.value.balance_cents,
        status: form.value.status,
        user_name: form.value.user_name,
        user_email: form.value.user_email,
        user_password: form.value.user_password,
      }
      await post('/admin/tenants', payload)
      toast.success('Tenant criado com sucesso')
    }
    getModal()?.hide()
    await load()
  } catch (e: any) {
    const msg = e?.response?.data?.message || (isEditing.value ? 'Erro ao atualizar tenant' : 'Erro ao criar tenant')
    toast.error(msg)
  } finally {
    isSaving.value = false
  }
}

// ---------- Delete ----------

async function remove(tenant: Tenant) {
  if (!confirm(`Excluir o tenant "${tenant.name}"? Todos os dados serão perdidos.`)) return
  try {
    await del(`/admin/tenants/${tenant.id}`)
    toast.success('Tenant excluído')
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao excluir tenant')
  }
}

// ---------- Credits modal ----------

function getCreditsModal() {
  if (!bsCreditsModal && creditsModalEl.value) {
    bsCreditsModal = new Modal(creditsModalEl.value)
  }
  return bsCreditsModal
}

function openCredits(tenant: Tenant) {
  creditsTenant.value = tenant
  creditsForm.value = { amount: 0, description: '' }
  nextTick(() => getCreditsModal()?.show())
}

async function addCredits() {
  if (!creditsTenant.value) return
  isSaving.value = true
  try {
    await post(`/admin/tenants/${creditsTenant.value.id}/credits`, {
      amount: creditsForm.value.amount,
      description: creditsForm.value.description || undefined,
    })
    toast.success(`Saldo de ${brl(creditsForm.value.amount)} adicionado`)
    getCreditsModal()?.hide()
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao adicionar saldo')
  } finally {
    isSaving.value = false
  }
}

// ---------- Init ----------

onMounted(() => {
  load()
  loadPlans()
})
</script>

<style scoped>
.table-clickable tbody tr {
  transition: background 0.15s;
}
.table-clickable tbody tr:hover {
  background: var(--bc-gray-soft);
}
.table-clickable td {
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
}
.btn-icon {
  width: 32px;
  height: 32px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
}
</style>
