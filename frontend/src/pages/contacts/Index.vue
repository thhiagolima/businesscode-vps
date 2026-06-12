<template>
  <div class="bc-page-enter">
    <!-- Inline header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 class="ct-title">Contatos</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">Gerencie suas listas e contatos</span>
      </div>
      <button class="btn btn-primary" style="border-radius:8px" data-bs-toggle="modal" data-bs-target="#modalImport">
        <i class="ti ti-upload me-1"></i> Importar CSV
      </button>
    </div>

    <!-- Content: sidebar + table -->
    <div class="d-flex gap-4">
      <!-- Sidebar -->
      <div class="ct-sidebar d-none d-md-flex flex-column">
        <!-- Segments header -->
        <div class="ct-sidebar__header">SEGMENTOS</div>

        <!-- Segment list -->
        <nav class="ct-segment-nav">
          <a class="ct-segment-item"
             :class="{ 'ct-segment-item--active': selectedListId === null }"
             href="#"
             @click.prevent="onListSelect(null)">
            <span>Todas</span>
            <span class="ct-segment-count">{{ totalContactCount }}</span>
          </a>
          <a v-for="list in lists" :key="list.id"
             class="ct-segment-item"
             :class="{ 'ct-segment-item--active': selectedListId === list.id }"
             href="#"
             @click.prevent="onListSelect(list.id)">
            <div class="d-flex align-items-center gap-2 flex-fill" style="min-width:0">
              <!-- Inline rename -->
              <template v-if="editingId === list.id">
                <input class="form-control form-control-sm" v-model="editName"
                       @keyup.enter="saveRename(list.id)" @keyup.escape="editingId = null"
                       @blur="saveRename(list.id)" ref="editInput" style="max-width:120px"
                       @click.stop>
              </template>
              <span v-else class="text-truncate" style="max-width:130px">{{ list.name }}</span>
            </div>
            <div class="d-flex align-items-center gap-1">
              <span class="ct-segment-count">{{ list.contact_count }}</span>
              <div class="dropdown" @click.stop>
                <a class="ct-segment-menu" href="#" data-bs-toggle="dropdown"><i class="ti ti-dots-vertical"></i></a>
                <div class="dropdown-menu">
                  <a class="dropdown-item" href="#" @click.prevent="startRename(list)"><i class="ti ti-pencil me-2"></i>Renomear</a>
                  <a class="dropdown-item text-danger" href="#" @click.prevent="deleteList(list.id)"><i class="ti ti-trash me-2"></i>Excluir</a>
                </div>
              </div>
            </div>
          </a>
        </nav>

        <!-- New list form -->
        <div class="ct-sidebar__footer">
          <div v-if="showNewForm" class="input-group input-group-sm">
            <input class="form-control" v-model="newName" placeholder="Nome da lista" @keyup.enter="createList" @keyup.escape="showNewForm = false">
            <button class="btn btn-primary btn-sm" @click="createList" :disabled="!newName">
              <i class="ti ti-check"></i>
            </button>
          </div>
          <button v-else class="btn btn-outline-primary btn-sm w-100" @click="showNewForm = true">
            <i class="ti ti-plus me-1"></i> Nova lista
          </button>
        </div>

        <!-- Database Health card (real data via /dashboard/stats) -->
        <div class="ct-health-card glass-card">
          <div class="ct-health-card__title">Saúde da Base</div>
          <div class="ct-health-card__row">
            <span class="ct-health-card__pct">
              <template v-if="dbHealthPct !== null">{{ dbHealthPct }}%</template>
              <template v-else>—</template>
            </span>
            <span class="ct-health-card__label">contatos válidos</span>
          </div>
          <div class="ct-health-bar">
            <div class="ct-health-bar__fill" :style="{ width: (dbHealthPct ?? 0) + '%' }"></div>
          </div>
        </div>
      </div>

      <!-- Main content -->
      <div class="flex-fill" style="min-width:0">
        <!-- Batch actions bar -->
        <BatchActionsBar v-if="selectedIds.size > 0"
          :selected-count="selectedIds.size"
          :lists="lists"
          @move="onBatchMove"
          @status="onBatchStatus"
          @delete="onBatchDelete"
          @clear="selectedIds.clear()"
        />

        <div class="card" style="border-radius:var(--bc-radius-lg)">
          <!-- FilterBar -->
          <div class="card-body pb-0">
            <FilterBar
              :search="filters.search"
              @update:search="filters.search = $event"
              search-placeholder="Buscar nome, telefone, email..."
            >
              <select class="form-select" v-model="filters.status">
                <option value="">Todos os status</option>
                <option value="active">Ativo</option>
                <option value="blocked">Bloqueado</option>
                <option value="invalid">Inválido</option>
              </select>
            </FilterBar>
          </div>

          <!-- Loading skeleton -->
          <TableSkeleton v-if="isLoading" :rows="6" :cols="5" />

          <!-- Table with avatar column -->
          <div v-else-if="contacts.length" class="table-responsive">
            <table class="table card-table table-vcenter table-clickable">
              <thead>
                <tr>
                  <th style="width:1%">
                    <input class="form-check-input m-0 align-middle" type="checkbox"
                           :checked="allSelected" @change="toggleAll">
                  </th>
                  <th style="width:3%"></th>
                  <th>Nome</th>
                  <th>Email / Telefone</th>
                  <th>Último Acesso</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in contacts" :key="c.id">
                  <td>
                    <input class="form-check-input m-0 align-middle" type="checkbox"
                           :checked="selectedIds.has(c.id)" @change="toggleOne(c.id)">
                  </td>
                  <td>
                    <span class="ct-avatar" :style="{ background: avatarColor(c.name || c.phone || '?') }">
                      {{ avatarInitials(c.name || c.phone || '?') }}
                    </span>
                  </td>
                  <td class="fw-medium" style="color:var(--bc-text)">{{ c.name || '—' }}</td>
                  <td>
                    <div style="font-size:0.82rem;color:var(--bc-text)">{{ c.email || '—' }}</div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:0.78rem;color:var(--bc-text-muted)">{{ c.phone || '' }}</div>
                  </td>
                  <td style="font-size:0.82rem;color:var(--bc-text-muted)">{{ c.updated_at ? formatDate(c.updated_at) : '—' }}</td>
                  <td><StatusBadge :label="statusLabel(c.status)" :status="c.status" dot /></td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Empty state -->
          <EmptyState
            v-else
            icon="ti-users"
            title="Nenhum contato encontrado"
            description="Importe um CSV para começar a gerenciar seus contatos."
          >
            <button class="btn btn-primary" style="border-radius:8px" data-bs-toggle="modal" data-bs-target="#modalImport">
              <i class="ti ti-upload me-1"></i> Importar CSV
            </button>
          </EmptyState>

          <!-- Pagination footer -->
          <div v-if="contacts.length" class="card-footer d-flex justify-content-between align-items-center">
            <span style="font-size:0.82rem;color:var(--bc-text-muted)">
              <span style="font-family:'JetBrains Mono',monospace">{{ pagination.total }}</span> contatos
            </span>
            <div class="d-flex gap-1">
              <button v-for="pg in pagination.last_page" :key="pg"
                      class="btn btn-sm"
                      style="border-radius:8px"
                      :class="pg === pagination.current_page ? 'btn-primary' : 'btn-ghost-secondary'"
                      @click="fetchContacts(pg)">
                {{ pg }}
              </button>
            </div>
          </div>
        </div>

        <!-- KPI cards row (real data via /dashboard/stats — sem placeholders) -->
        <div class="ct-kpi-row">
          <div class="ct-kpi glass-card">
            <div class="ct-kpi__label">Contatos Ativos</div>
            <div class="ct-kpi__value">
              <template v-if="activeContactsRate !== null">{{ activeContactsRate }}%</template>
              <template v-else>—</template>
            </div>
            <div class="ct-kpi__sub">do total da base</div>
          </div>
          <div class="ct-kpi glass-card">
            <div class="ct-kpi__label">Automações Ativas</div>
            <div class="ct-kpi__value">
              <template v-if="activeAutomations !== null">{{ activeAutomations }}</template>
              <template v-else>—</template>
            </div>
            <div class="ct-kpi__sub">workflows em execução</div>
          </div>
          <div class="ct-kpi glass-card">
            <div class="ct-kpi__label">Novos Contatos</div>
            <div class="ct-kpi__value">
              <template v-if="newContactsThisMonth !== null">+{{ newContactsThisMonth }}</template>
              <template v-else>—</template>
            </div>
            <div class="ct-kpi__sub">este mês</div>
          </div>
        </div>
      </div>
    </div>

    <ImportCsvModal @submitted="onImported" />
  </div>

  <ConfirmModal
    :visible="showBatchDeleteModal"
    title="Deletar contatos"
    message="Tem certeza que deseja deletar os contatos selecionados?"
    confirm-text="Deletar"
    @confirm="confirmBatchDelete"
    @cancel="showBatchDeleteModal = false"
  />

  <ConfirmModal
    :visible="pendingDeleteListId !== null"
    title="Excluir lista"
    message="Tem certeza que deseja excluir esta lista? Os contatos vinculados não serão excluídos, apenas a lista."
    confirm-text="Excluir"
    @confirm="confirmDeleteList"
    @cancel="pendingDeleteListId = null"
  />
</template>

<script setup lang="ts">
import { ref, onMounted, watch, computed, nextTick } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import ImportCsvModal from '@/components/contacts/ImportCsvModal.vue'
import BatchActionsBar from '@/components/contacts/BatchActionsBar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import FilterBar from '@/components/ui/FilterBar.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'

const { get, post, put, del } = useApi()
const toast = useToast()

type ContactList = { id: number; name: string; contact_count: number }
type Contact = { id: number; name?: string; phone?: string; email?: string; status: string; updated_at?: string }

const lists = ref<ContactList[]>([])
const contacts = ref<Contact[]>([])
const selectedListId = ref<number | null>(null)
const selectedIds = ref<Set<number>>(new Set())
const filters = ref({ search: '', status: '' })
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 })
const isLoading = ref(false)
const showBatchDeleteModal = ref(false)
const pendingDeleteListId = ref<number | null>(null)

// ─── Sidebar inline state (moved from ContactsSidebar) ───
const editingId = ref<number | null>(null)
const editName = ref('')
const editInput = ref<HTMLInputElement[] | null>(null)
const showNewForm = ref(false)
const newName = ref('')

const totalContactCount = computed(() => lists.value.reduce((sum, l) => sum + l.contact_count, 0))

// ─── KPIs reais via /dashboard/stats ─────────────────────────────────
// Os 3 cards (Engajamento/Automações/Novos) e o card de Saúde da Base
// consomem AGORA dados reais do tenant. Não há mais placeholders.
const kpisLoaded = ref(false)
const contactsTotal = ref<number | null>(null)
const contactsValid = ref<number | null>(null)
const contactsActive = ref<number | null>(null)
const newContactsMtdValue = ref<number | null>(null)
const activeAutomationsValue = ref<number | null>(null)

async function fetchKpis() {
  try {
    const res = await get<any>('/dashboard/stats')
    const k = res?.data?.kpis ?? res?.kpis ?? {}
    contactsTotal.value          = Number(k.contacts_total ?? 0)
    contactsValid.value          = Number(k.contacts_valid ?? 0)
    contactsActive.value         = Number(k.contacts_active ?? 0)
    newContactsMtdValue.value    = Number(k.new_contacts_mtd ?? 0)
    activeAutomationsValue.value = Number(k.active_automations ?? 0)
    kpisLoaded.value = true
  } catch {
    // Em caso de falha, NÃO mostrar números fake — manter como null
    // (template exibe "—" via v-if/v-else).
    kpisLoaded.value = false
  }
}

// Renomeado no template para "Contatos Ativos" (é o que de fato calculamos).
const activeContactsRate = computed(() => {
  if (!kpisLoaded.value || !contactsTotal.value) return null
  return Math.round((contactsActive.value! / contactsTotal.value) * 100)
})
const activeAutomations = computed(() => activeAutomationsValue.value)
const newContactsThisMonth = computed(() => newContactsMtdValue.value)

// Saúde da base: razão válidos/total. Sem base = "—", NÃO 100%.
const dbHealthPct = computed<number | null>(() => {
  if (!kpisLoaded.value || !contactsTotal.value) return null
  return Math.round((contactsValid.value! / contactsTotal.value) * 100)
})

// ─── Avatar helpers ───
const avatarColors = ['#0064ff', '#25d366', '#d97706', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#f59e0b']
function avatarColor(name: string): string {
  let hash = 0
  for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash)
  return avatarColors[Math.abs(hash) % avatarColors.length]
}
function avatarInitials(name: string): string {
  const parts = name.trim().split(/\s+/)
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  return name.substring(0, 2).toUpperCase()
}

function formatDate(dateStr: string): string {
  try {
    const d = new Date(dateStr)
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' })
  } catch {
    return '—'
  }
}

// --- Data fetching ---

async function fetchLists() {
  const res = await get<any>('/contact-lists')
  lists.value = res?.data ?? res ?? []
}

async function fetchContacts(page = 1) {
  isLoading.value = true
  try {
    const res = await get<any>('/contacts', {
      page,
      contact_list_id: selectedListId.value || undefined,
      search: filters.value.search || undefined,
      status: filters.value.status || undefined,
    })
    contacts.value = res?.data ?? res ?? []
    if (res?.meta) pagination.value = res.meta
  } finally {
    isLoading.value = false
  }
}

// --- Sidebar handlers ---

function onListSelect(id: number | null) {
  selectedListId.value = id
  selectedIds.value.clear()
  fetchContacts(1)
}

function startRename(list: { id: number; name: string }) {
  editingId.value = list.id
  editName.value = list.name
  nextTick(() => {
    if (editInput.value && editInput.value.length > 0) {
      editInput.value[0].focus()
    }
  })
}

async function saveRename(id: number) {
  if (!editName.value.trim() || editingId.value !== id) return
  try {
    await put(`/contact-lists/${id}`, { name: editName.value.trim() })
    await fetchLists()
    toast.success('Lista renomeada')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao renomear lista')
  } finally {
    editingId.value = null
  }
}

// Substituído o window.confirm() nativo (não estilizável + bloqueia event loop)
// pelo ConfirmModal do design system. Estado é gerenciado por pendingDeleteListId
// porque a exclusão é disparada pelo botão de lixeira na lista (passa o id).
function deleteList(id: number) {
  pendingDeleteListId.value = id
}

async function confirmDeleteList() {
  const id = pendingDeleteListId.value
  if (id === null) return
  try {
    await del(`/contact-lists/${id}`)
    selectedListId.value = null
    await fetchLists()
    fetchContacts(1)
    toast.success('Lista excluída')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao excluir lista')
  } finally {
    pendingDeleteListId.value = null
  }
}

async function createList() {
  if (!newName.value.trim()) return
  try {
    await post('/contact-lists', { name: newName.value.trim() })
    await fetchLists()
    toast.success('Lista criada')
    newName.value = ''
    showNewForm.value = false
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao criar lista')
  }
}

// --- Batch action handlers ---

async function onBatchMove(listId: number) {
  try {
    await post('/contacts/batch', { action: 'move', contact_ids: [...selectedIds.value], target_list_id: listId })
    toast.success('Contatos movidos')
    selectedIds.value.clear()
    await fetchLists()
    fetchContacts(pagination.value.current_page)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

async function onBatchStatus(status: string) {
  try {
    await post('/contacts/batch', { action: 'status', contact_ids: [...selectedIds.value], target_status: status })
    toast.success('Status atualizado')
    selectedIds.value.clear()
    fetchContacts(pagination.value.current_page)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

function onBatchDelete() {
  showBatchDeleteModal.value = true
}

async function confirmBatchDelete() {
  showBatchDeleteModal.value = false
  try {
    await post('/contacts/batch', { action: 'delete', contact_ids: [...selectedIds.value] })
    toast.success('Contatos deletados')
    selectedIds.value.clear()
    await fetchLists()
    fetchContacts(pagination.value.current_page)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

// --- Checkbox logic ---

const allSelected = computed(() => contacts.value.length > 0 && contacts.value.every(c => selectedIds.value.has(c.id)))

function toggleAll() {
  if (allSelected.value) {
    selectedIds.value.clear()
  } else {
    contacts.value.forEach(c => selectedIds.value.add(c.id))
  }
}

function toggleOne(id: number) {
  if (selectedIds.value.has(id)) selectedIds.value.delete(id)
  else selectedIds.value.add(id)
}

// --- Status helpers ---

const statusLabel = (s: string) => ({
  active: 'Ativo',
  blocked: 'Bloqueado',
  invalid: 'Inválido',
}[s] ?? s)

// --- Search debounce ---

let searchTimer: any
watch(() => filters.value.search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => fetchContacts(1), 300)
})
watch(() => filters.value.status, () => fetchContacts(1))

// --- Import handler ---

function onImported() {
  fetchLists()
  fetchContacts(1)
}

// --- Init ---

onMounted(() => {
  fetchLists()
  fetchContacts()
  fetchKpis()
})
</script>

<style scoped>
/* ─── Page title ─── */
.ct-title {
  font-family: 'Manrope', sans-serif;
  font-size: 1.4rem;
  font-weight: 700;
  letter-spacing: -0.03em;
  margin: 0;
  color: var(--bc-text);
}

/* ─── Sidebar ─── */
.ct-sidebar {
  width: 240px;
  flex-shrink: 0;
  gap: 0;
}
.ct-sidebar__header {
  font-family: 'JetBrains Mono', monospace;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--bc-text-muted);
  padding: 0 0.5rem 0.75rem 0.5rem;
}
.ct-sidebar__footer {
  padding: 0.75rem 0.5rem;
}

/* ─── Segment nav items ─── */
.ct-segment-nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.ct-segment-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.55rem 0.65rem;
  border-radius: var(--bc-radius);
  color: var(--bc-text-muted);
  text-decoration: none;
  font-size: 0.85rem;
  font-weight: 500;
  transition: var(--bc-transition);
  cursor: pointer;
}
.ct-segment-item:hover {
  background: var(--bc-primary-subtle);
  color: var(--bc-text);
}
.ct-segment-item--active {
  background: linear-gradient(135deg, rgba(0,100,255,0.15), rgba(0,100,255,0.08));
  color: var(--bc-white);
  font-weight: 600;
}
.ct-segment-count {
  font-family: 'JetBrains Mono', monospace;
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--bc-text-muted);
  background: var(--bc-gray-soft);
  padding: 0.15em 0.55em;
  border-radius: 9999px;
}
.ct-segment-item--active .ct-segment-count {
  background: rgba(0, 100, 255, 0.2);
  color: var(--bc-primary);
}
.ct-segment-menu {
  color: var(--bc-text-muted);
  opacity: 0;
  transition: opacity 0.15s;
  font-size: 14px;
  text-decoration: none;
}
.ct-segment-item:hover .ct-segment-menu {
  opacity: 1;
}

/* ─── Database Health card ─── */
.ct-health-card {
  margin-top: auto;
  padding: 1rem;
  border-radius: var(--bc-radius-lg);
}
.ct-health-card__title {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--bc-text-muted);
  margin-bottom: 0.6rem;
}
.ct-health-card__row {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  margin-bottom: 0.5rem;
}
.ct-health-card__pct {
  font-family: 'Manrope', sans-serif;
  font-size: 1.4rem;
  font-weight: 800;
  color: var(--bc-text);
  letter-spacing: -0.02em;
}
.ct-health-card__label {
  font-size: 0.78rem;
  color: var(--bc-text-muted);
}
.ct-health-bar {
  height: 6px;
  background: var(--bc-gray-soft);
  border-radius: 3px;
  overflow: hidden;
}
.ct-health-bar__fill {
  height: 100%;
  background: linear-gradient(90deg, var(--bc-success), #34d399);
  border-radius: 3px;
  transition: width 0.4s ease;
}

/* ─── Avatar circles ─── */
.ct-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  font-size: 0.7rem;
  font-weight: 700;
  color: #fff;
  letter-spacing: 0.02em;
  flex-shrink: 0;
}

/* ─── Table dark adjustments ─── */
.table-clickable tbody tr {
  cursor: pointer;
  transition: background 0.15s;
}
.table-clickable tbody tr:hover {
  background: rgba(0, 100, 255, 0.04);
}
.table-clickable td {
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
}

/* ─── KPI cards row ─── */
.ct-kpi-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  margin-top: 1rem;
}
.ct-kpi {
  padding: 1.1rem 1.2rem;
  border-radius: var(--bc-radius-lg);
  text-align: center;
}
.ct-kpi__label {
  font-size: 0.68rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--bc-text-muted);
  margin-bottom: 0.4rem;
}
.ct-kpi__value {
  font-family: 'Manrope', sans-serif;
  font-size: 1.6rem;
  font-weight: 800;
  color: var(--bc-text);
  letter-spacing: -0.03em;
  line-height: 1.1;
}
.ct-kpi__sub {
  font-size: 0.72rem;
  color: var(--bc-text-muted);
  margin-top: 0.25rem;
}

/* ─── Responsive ─── */
@media (max-width: 767.98px) {
  .ct-sidebar {
    display: none !important;
  }
  .ct-kpi-row {
    grid-template-columns: 1fr;
  }
}
@media (min-width: 768px) and (max-width: 991px) {
  .ct-kpi-row {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
