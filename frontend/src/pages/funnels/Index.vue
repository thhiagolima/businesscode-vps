<template>
  <div>
    <!-- Inline header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Funis</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">Sequências automatizadas de mensagens WhatsApp</span>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" style="border-radius:8px" @click="($refs.importFile as HTMLInputElement)?.click()">
          <i class="ti ti-upload me-1"></i> Importar
        </button>
        <input ref="importFile" type="file" accept=".json" style="display:none" @change="importFunnel">
        <button class="btn btn-primary" style="border-radius:8px" @click="createFunnel" :disabled="creating">
          <span v-if="creating" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-plus me-1"></i> Novo funil
        </button>
      </div>
    </div>

    <div class="card" style="border-radius:14px">
      <!-- Loading skeleton -->
      <TableSkeleton v-if="loading" :rows="5" :cols="6" />

      <!-- Empty state -->
      <EmptyState
        v-else-if="!funnels.length"
        icon="ti-chart-funnel"
        title="Nenhum funil criado"
        description="Crie sequências automatizadas de mensagens WhatsApp."
      >
        <button class="btn btn-primary" style="border-radius:8px" @click="createFunnel">
          <i class="ti ti-plus me-1"></i> Criar primeiro funil
        </button>
      </EmptyState>

      <!-- Table -->
      <div v-else class="table-responsive">
        <table class="table card-table table-vcenter table-clickable">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Status</th>
              <th>Contatos ativos</th>
              <th>Triggers</th>
              <th>Criado em</th>
              <th style="width:140px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in funnels" :key="f.id">
              <td class="fw-bold">{{ f.name }}</td>
              <td><StatusBadge :label="statusLabel(f.status)" :status="f.status" dot /></td>
              <td style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">{{ f.active_contacts ?? 0 }}</td>
              <td>
                <StatusBadge v-if="f.is_default" label="catch-all" color="secondary" />
                <StatusBadge
                  v-for="(t, i) in (f.triggers ?? []).filter((t: any) => t.type === 'keyword')"
                  :key="i"
                  :label="t.pattern"
                  color="primary"
                  class="me-1"
                />
                <span v-if="!f.is_default && !(f.triggers ?? []).length" class="text-muted">&mdash;</span>
              </td>
              <td class="text-muted">{{ formatDate(f.created_at) }}</td>
              <td>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-icon btn-ghost-primary" @click="$router.push(`/funnels/${f.id}/edit`)" title="Editar">
                    <i class="ti ti-pencil"></i>
                  </button>
                  <button class="btn btn-sm btn-icon btn-ghost-secondary" @click="exportFunnel(f.id, f.name)" title="Exportar JSON">
                    <i class="ti ti-download"></i>
                  </button>
                  <button v-if="f.status !== 'active'" class="btn btn-sm btn-icon btn-ghost-success" @click="activateFunnel(f.id)" title="Ativar">
                    <i class="ti ti-player-play"></i>
                  </button>
                  <button v-if="f.status === 'active'" class="btn btn-sm btn-icon btn-ghost-warning" @click="pauseFunnel(f.id)" title="Pausar">
                    <i class="ti ti-player-pause"></i>
                  </button>
                  <button class="btn btn-sm btn-icon btn-ghost-danger" @click="deleteFunnel(f.id)" title="Deletar">
                    <i class="ti ti-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <ConfirmModal
    :visible="showDeleteModal"
    title="Deletar funil"
    message="Deletar este funil? Todas as execuções serão canceladas."
    confirm-text="Deletar"
    @confirm="confirmDelete"
    @cancel="showDeleteModal = false; deleteTarget = null"
  />
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'

const { get, post, del, upload } = useApi()
const toast = useToast()
const router = useRouter()

const funnels = ref<any[]>([])
const loading = ref(false)
const creating = ref(false)
const showDeleteModal = ref(false)
const deleteTarget = ref<number | null>(null)

async function load() {
  loading.value = true
  try {
    const res = await get<any>('/funnels')
    funnels.value = res?.data ?? res ?? []
  } finally { loading.value = false }
}

async function createFunnel() {
  creating.value = true
  try {
    const res = await post<any>('/funnels', { name: 'Novo funil' })
    const id = res?.id
    if (id) router.push(`/funnels/${id}/edit`)
    else await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') }
  finally { creating.value = false }
}

async function activateFunnel(id: number) {
  try {
    await post(`/funnels/${id}/activate`, {})
    toast.success('Funil ativado')
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') }
}

async function pauseFunnel(id: number) {
  try {
    await post(`/funnels/${id}/pause`, {})
    toast.success('Funil pausado')
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') }
}

function deleteFunnel(id: number) {
  deleteTarget.value = id
  showDeleteModal.value = true
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  try {
    await del(`/funnels/${deleteTarget.value}`)
    toast.success('Funil removido')
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') } finally {
    showDeleteModal.value = false
    deleteTarget.value = null
  }
}

function statusBadge(s: string) {
  return { draft: 'bg-secondary', active: 'bg-success', paused: 'bg-warning text-dark' }[s] ?? 'bg-secondary'
}
function statusLabel(s: string) {
  return { draft: 'Rascunho', active: 'Ativo', paused: 'Pausado' }[s] ?? s
}
function formatDate(d: string) {
  return d ? new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) : '—'
}

async function exportFunnel(id: number, name: string) {
  const res = await get<any>(`/funnels/${id}/export`)
  const blob = new Blob([JSON.stringify(res, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `${name.replace(/\s+/g, '-')}.json`
  a.click()
  URL.revokeObjectURL(url)
}

async function importFunnel(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const fd = new FormData()
  fd.append('file', file)
  try {
    await upload('/funnels/import', fd)
    toast.success('Funil importado')
    load()
  } catch (err: any) {
    toast.error(err?.response?.data?.message ?? 'Erro ao importar')
  }
}

onMounted(load)
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
