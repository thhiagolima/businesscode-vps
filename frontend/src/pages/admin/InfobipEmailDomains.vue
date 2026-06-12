<template>
  <div class="page-header d-print-none">
    <div class="container-xl">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="page-title">Domínios de Email via Infobip</h2>
        <button class="btn btn-primary" @click="syncDomains" :disabled="isSyncing">
          <span v-if="isSyncing" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-refresh me-1"></i>
          Sincronizar domínios
        </button>
      </div>
    </div>
  </div>
  <div class="page-body">
    <div class="container-xl">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Domínios disponíveis</h3>
          <div class="card-actions">
            <span class="text-muted small">{{ domains.length }} domínios</span>
          </div>
        </div>
        <div v-if="isLoading" class="card-body">
          <div class="placeholder-glow">
            <div v-for="n in 3" :key="n" class="mb-2"><span class="placeholder col-8"></span></div>
          </div>
        </div>
        <div v-else-if="!domains.length" class="card-body text-center py-5">
          <i class="ti ti-mail" style="font-size:3rem;color:#ccc"></i>
          <p class="text-muted mt-2">Nenhum domínio encontrado. Clique "Sincronizar" para buscar da API Infobip.</p>
        </div>
        <div v-else class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead>
              <tr>
                <th>Domínio</th>
                <th>Status</th>
                <th>DKIM</th>
                <th>SPF</th>
                <th>Return-Path</th>
                <th>Tenant atribuído</th>
                <th style="width:180px">Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in domains" :key="d.id">
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <i class="ti ti-at text-blue"></i>
                    <strong>{{ d.domain }}</strong>
                  </div>
                  <div v-if="d.infobip_domain_id" class="text-muted small">Infobip ID: {{ d.infobip_domain_id }}</div>
                </td>
                <td><StatusBadge :label="statusLabel(d.status)" :status="statusToKey(d.status)" dot /></td>
                <td><i :class="d.dkim_verified ? 'ti ti-check text-green' : 'ti ti-x text-muted'"></i></td>
                <td><i :class="d.spf_verified ? 'ti ti-check text-green' : 'ti ti-x text-muted'"></i></td>
                <td><i :class="d.return_path_verified ? 'ti ti-check text-green' : 'ti ti-x text-muted'"></i></td>
                <td>
                  <RouterLink
                    v-if="d.tenant"
                    :to="`/admin/billing/tenants/${d.tenant.id}`"
                    class="bc-tenant-link"
                  >
                    <i class="ti ti-external-link me-1"></i>{{ d.tenant.name }}
                  </RouterLink>
                  <span v-else style="color:var(--bc-text-muted,#6c7293);font-size:0.82rem">Disponível</span>
                </td>
                <td>
                  <button
                    v-if="d.tenant"
                    class="btn btn-sm btn-outline-danger"
                    @click="unassign(d)"
                    :disabled="busyId === d.id"
                  >
                    <span v-if="busyId === d.id" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ti ti-link-off me-1"></i>
                    Desassociar
                  </button>
                  <button
                    v-else
                    class="btn btn-sm btn-outline-primary"
                    @click="openAssignModal(d)"
                    :disabled="busyId === d.id"
                  >
                    <i class="ti ti-link me-1"></i>
                    Associar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="domains.length" class="card-footer text-muted small">
          <i class="ti ti-info-circle me-1"></i>
          Ao atribuir um domínio a um tenant, o provider de email dele será automaticamente configurado para Infobip.
        </div>
      </div>
    </div>
  </div>

  <!-- Assign modal -->
  <div ref="modalEl" class="modal modal-blur fade" tabindex="-1" aria-labelledby="assignEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="assignEmailModalTitle">
            Associar domínio
            <span v-if="selectedDomain" class="text-muted ms-1" style="font-weight:400">{{ selectedDomain.domain }}</span>
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

const domains = ref<any[]>([])
const isLoading = ref(false)
const isSyncing = ref(false)
const busyId = ref<number | null>(null)

const modalEl = ref<HTMLElement | null>(null)
let bsModal: Modal | null = null
const selectedDomain = ref<any | null>(null)

const search = ref('')
const searchResults = ref<any[]>([])
const searchLoading = ref(false)
let searchTimer: any = null

function statusLabel(status: string): string {
  const map: Record<string, string> = {
    pending: 'Pendente', verifying: 'Verificando', active: 'Ativo', failed: 'Falhou',
  }
  return map[status?.toLowerCase()] ?? status
}

function statusToKey(status: string): string {
  const s = status?.toLowerCase()
  if (s === 'active') return 'active'
  if (s === 'failed') return 'blocked'
  if (s === 'pending' || s === 'verifying') return 'trial'
  return 'inactive'
}

async function loadDomains() {
  isLoading.value = true
  try {
    const res = await get<any>('/admin/infobip-email/domains')
    domains.value = Array.isArray(res) ? res : (res?.data ?? [])
  } catch { domains.value = [] }
  finally { isLoading.value = false }
}

async function syncDomains() {
  isSyncing.value = true
  try {
    const res = await post<any>('/admin/infobip-email/sync')
    toast.success(res?.message ?? 'Sincronizado')
    await loadDomains()
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
  if (q.length === 1) return
  searchTimer = setTimeout(() => loadSearch(q), 300)
}

function openAssignModal(d: any) {
  selectedDomain.value = d
  search.value = ''
  searchResults.value = []
  loadSearch('')
  nextTick(() => getModal()?.show())
}

async function confirmAssign(tenant: any) {
  if (!selectedDomain.value) return
  const domainId = selectedDomain.value.id
  busyId.value = domainId
  try {
    await put(`/admin/infobip-email/domains/${domainId}/assign`, { tenant_id: tenant.id })
    toast.success(`Domínio associado a ${tenant.name}`)
    getModal()?.hide()
    await loadDomains()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao associar')
  } finally {
    busyId.value = null
  }
}

async function unassign(d: any) {
  const tenantName = d.tenant?.name ?? 'este tenant'
  if (!confirm(`Desassociar o domínio ${d.domain} de "${tenantName}"?`)) return
  busyId.value = d.id
  try {
    await put(`/admin/infobip-email/domains/${d.id}/assign`, { tenant_id: null })
    toast.success('Domínio desassociado')
    await loadDomains()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao desassociar')
  } finally {
    busyId.value = null
  }
}

onMounted(() => {
  loadDomains()
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
.bc-tenant-list { max-height: 360px; overflow-y: auto; }
.bc-tenant-row {
  display: flex; align-items: center;
  padding: 0.65rem 0.75rem; border-radius: 8px;
  cursor: pointer; border: 1px solid transparent;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.bc-tenant-row:hover { background: rgba(0, 100, 255, 0.06); border-color: rgba(0, 100, 255, 0.18); }
.bc-tenant-row + .bc-tenant-row { margin-top: 2px; }
</style>
