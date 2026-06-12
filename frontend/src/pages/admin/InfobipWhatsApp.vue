<template>
  <div class="page-header d-print-none">
    <div class="container-xl">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="page-title">WhatsApp via Infobip</h2>
        <button class="btn btn-primary" @click="syncNumbers" :disabled="isSyncing">
          <span v-if="isSyncing" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-refresh me-1"></i>
          Sincronizar números
        </button>
      </div>
    </div>
  </div>
  <div class="page-body">
    <div class="container-xl">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Números WhatsApp disponíveis</h3>
          <div class="card-actions">
            <span class="text-muted small">{{ numbers.length }} números</span>
          </div>
        </div>
        <div v-if="isLoading" class="card-body">
          <div class="placeholder-glow">
            <div v-for="n in 3" :key="n" class="mb-2"><span class="placeholder col-8"></span></div>
          </div>
        </div>
        <div v-else-if="!numbers.length" class="card-body text-center py-5">
          <i class="ti ti-brand-whatsapp" style="font-size:3rem;color:#ccc"></i>
          <p class="text-muted mt-2">Nenhum número encontrado. Clique "Sincronizar" para buscar da API Infobip.</p>
        </div>
        <div v-else class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead>
              <tr>
                <th>Número</th>
                <th>Nome</th>
                <th>Status</th>
                <th>Tenant atribuído</th>
                <th style="width:180px">Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="num in numbers" :key="num.id">
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <i class="ti ti-brand-whatsapp text-green"></i>
                    <strong>{{ num.number }}</strong>
                  </div>
                  <div class="text-muted small">Sender: {{ num.sender }}</div>
                </td>
                <td>{{ num.display_name || '—' }}</td>
                <td>
                  <StatusBadge :label="statusLabel(num.status)" :status="statusToKey(num.status)" dot />
                </td>
                <td>
                  <RouterLink
                    v-if="num.tenant"
                    :to="`/admin/billing/tenants/${num.tenant.id}`"
                    class="bc-tenant-link"
                  >
                    <i class="ti ti-external-link me-1"></i>{{ num.tenant.name }}
                  </RouterLink>
                  <span v-else style="color:var(--bc-text-muted,#6c7293);font-size:0.82rem">Disponível</span>
                </td>
                <td>
                  <button
                    v-if="num.tenant"
                    class="btn btn-sm btn-outline-danger"
                    @click="unassign(num)"
                    :disabled="busyId === num.id"
                  >
                    <span v-if="busyId === num.id" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ti ti-link-off me-1"></i>
                    Desassociar
                  </button>
                  <button
                    v-else
                    class="btn btn-sm btn-outline-primary"
                    @click="openAssignModal(num)"
                    :disabled="busyId === num.id"
                  >
                    <i class="ti ti-link me-1"></i>
                    Associar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="numbers.length" class="card-footer text-muted small">
          <i class="ti ti-info-circle me-1"></i>
          Ao atribuir um número a um tenant, o provider WhatsApp dele será automaticamente configurado para Infobip.
          <br>
          Configure o webhook no painel Infobip: <code>{{ webhookUrl }}</code>
        </div>
      </div>
    </div>
  </div>

  <!-- Assign modal -->
  <div ref="modalEl" class="modal modal-blur fade" tabindex="-1" aria-labelledby="assignNumberModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="assignNumberModalTitle">
            Associar número
            <span v-if="selectedNumber" class="text-muted ms-1" style="font-weight:400">{{ selectedNumber.number }}</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <input
              v-model="search"
              @input="onSearch"
              type="search"
              class="form-control"
              placeholder="Buscar por nome ou slug (mín. 2 caracteres)..."
              autocomplete="off"
            />
          </div>
          <div v-if="searchLoading" class="text-center py-4">
            <span class="spinner-border spinner-border-sm"></span>
          </div>
          <div v-else-if="!searchResults.length" class="text-muted text-center py-4">
            <i class="ti ti-mood-empty d-block mb-2" style="font-size:1.5rem"></i>
            Nenhum tenant encontrado
          </div>
          <ul v-else class="list-unstyled mb-0 bc-tenant-list">
            <li
              v-for="t in searchResults"
              :key="t.id"
              class="bc-tenant-row"
              @click="confirmAssign(t)"
            >
              <div class="flex-fill" style="min-width:0">
                <div class="text-truncate" style="font-weight:600">{{ t.name }}</div>
                <div class="text-muted small text-truncate">{{ t.slug }}</div>
              </div>
              <i class="ti ti-chevron-right text-muted ms-2"></i>
            </li>
          </ul>
          <div v-if="!searchLoading && searchResults.length && !search" class="text-muted small mt-2 text-center">
            Mostrando os {{ searchResults.length }} primeiros tenants. Digite para refinar a busca.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import { RouterLink } from 'vue-router'
import { Modal } from 'bootstrap'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import StatusBadge from '@/components/ui/StatusBadge.vue'

const { get, put, post } = useApi()
const toast = useToast()

const numbers = ref<any[]>([])
const isLoading = ref(false)
const isSyncing = ref(false)
const busyId = ref<number | null>(null)
const webhookUrl = ref(window.location.origin + '/api/v1/webhooks/infobip/whatsapp')

const modalEl = ref<HTMLElement | null>(null)
let bsModal: Modal | null = null
const selectedNumber = ref<any | null>(null)

const search = ref('')
const searchResults = ref<any[]>([])
const searchLoading = ref(false)
let searchTimer: any = null

function statusLabel(status: string): string {
  const map: Record<string, string> = {
    active: 'Conectado', inactive: 'Inativo', banned: 'Banido', connected: 'Conectado',
    disconnected: 'Desconectado', pending: 'Pendente',
  }
  return map[status?.toLowerCase()] ?? status
}

function statusToKey(status: string): string {
  const s = status?.toLowerCase()
  if (s === 'active' || s === 'connected') return 'active'
  if (s === 'banned') return 'blocked'
  if (s === 'pending') return 'trial'
  if (s === 'disconnected') return 'inactive'
  return 'inactive'
}

async function loadNumbers() {
  isLoading.value = true
  try {
    const res = await get<any>('/admin/infobip-whatsapp/numbers')
    numbers.value = Array.isArray(res) ? res : (res?.data ?? [])
  } catch { numbers.value = [] }
  finally { isLoading.value = false }
}

async function syncNumbers() {
  isSyncing.value = true
  try {
    const res = await post<any>('/admin/infobip-whatsapp/sync')
    toast.success(res?.message ?? 'Sincronizado')
    await loadNumbers()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao sincronizar')
  } finally {
    isSyncing.value = false
  }
}

function getModal(): Modal | null {
  if (!bsModal && modalEl.value) {
    bsModal = new Modal(modalEl.value)
  }
  return bsModal
}

async function loadSearch(query = '') {
  searchLoading.value = true
  try {
    const params = query.length >= 2 ? `?search=${encodeURIComponent(query)}` : ''
    const res = await get<any>(`/admin/tenants${params}`)
    searchResults.value = Array.isArray(res) ? res : (res?.data ?? [])
  } catch {
    searchResults.value = []
  } finally {
    searchLoading.value = false
  }
}

function onSearch() {
  clearTimeout(searchTimer)
  const q = search.value.trim()
  if (q.length === 1) return // backend requires min:2
  searchTimer = setTimeout(() => loadSearch(q), 300)
}

function openAssignModal(num: any) {
  selectedNumber.value = num
  search.value = ''
  searchResults.value = []
  loadSearch('')
  nextTick(() => getModal()?.show())
}

async function confirmAssign(tenant: any) {
  if (!selectedNumber.value) return
  const numberId = selectedNumber.value.id
  busyId.value = numberId
  try {
    await put(`/admin/infobip-whatsapp/numbers/${numberId}/assign`, { tenant_id: tenant.id })
    toast.success(`Número associado a ${tenant.name}`)
    getModal()?.hide()
    await loadNumbers()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao associar')
  } finally {
    busyId.value = null
  }
}

async function unassign(num: any) {
  const tenantName = num.tenant?.name ?? 'este tenant'
  if (!confirm(`Desassociar o número ${num.number} de "${tenantName}"?`)) return
  busyId.value = num.id
  try {
    await put(`/admin/infobip-whatsapp/numbers/${num.id}/assign`, { tenant_id: null })
    toast.success('Número desassociado')
    await loadNumbers()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao desassociar')
  } finally {
    busyId.value = null
  }
}

onMounted(() => {
  loadNumbers()
})
</script>

<style scoped>
.bc-tenant-link {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.6rem;
  font-size: 0.78rem;
  font-weight: 600;
  background: rgba(0, 100, 255, 0.1);
  color: #0064ff;
  border-radius: 999px;
  text-decoration: none;
  transition: background 0.15s ease;
}
.bc-tenant-link:hover {
  background: rgba(0, 100, 255, 0.18);
  color: #0064ff;
}

.bc-tenant-list {
  max-height: 360px;
  overflow-y: auto;
}
.bc-tenant-row {
  display: flex;
  align-items: center;
  padding: 0.65rem 0.75rem;
  border-radius: 8px;
  cursor: pointer;
  border: 1px solid transparent;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.bc-tenant-row:hover {
  background: rgba(0, 100, 255, 0.06);
  border-color: rgba(0, 100, 255, 0.18);
}
.bc-tenant-row + .bc-tenant-row {
  margin-top: 2px;
}
</style>
