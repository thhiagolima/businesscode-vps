<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-.03em;margin:0">Opt-outs</h2>
        <span style="font-size:.85rem;color:var(--bc-text-muted)">
          Lista LGPD-compliant de contatos que pediram para não receber mensagens.
        </span>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-secondary" style="border-radius:8px" @click="downloadCsv">
          <i class="ti ti-download me-1"></i>Exportar CSV
        </button>
        <label class="btn btn-outline-secondary mb-0" style="border-radius:8px;cursor:pointer">
          <i class="ti ti-upload me-1"></i>Importar CSV
          <input type="file" accept=".csv,.txt" hidden @change="onImportFile" />
        </label>
        <button class="btn btn-primary" style="border-radius:8px" @click="showAddModal = true">
          <i class="ti ti-plus me-1"></i>Adicionar
        </button>
      </div>
    </div>

    <!-- Importação status -->
    <div v-if="importResult" class="alert" :class="importResult.skipped ? 'alert-warning' : 'alert-success'">
      <strong>{{ importResult.imported }}</strong> opt-outs importados.
      <span v-if="importResult.skipped"><strong>{{ importResult.skipped }}</strong> ignorados (formato inválido).</span>
      <ul v-if="importResult.errors?.length" style="margin:0.5rem 0 0;padding-left:1.2rem">
        <li v-for="e in importResult.errors.slice(0, 5)" :key="e" style="font-size:0.8rem">{{ e }}</li>
      </ul>
    </div>

    <!-- Filtros -->
    <div class="card mb-3" style="border-radius:12px">
      <div class="card-body py-2 d-flex gap-2 flex-wrap align-items-end">
        <div style="min-width:160px">
          <label class="form-label small text-muted">Canal</label>
          <select class="form-select form-select-sm" v-model="filters.channel" @change="reload()">
            <option value="">Todos</option>
            <option value="all">Todos canais (global)</option>
            <option value="sms">SMS</option>
            <option value="voice">Voz</option>
            <option value="email">Email</option>
            <option value="whatsapp">WhatsApp</option>
          </select>
        </div>
        <div class="flex-grow-1" style="min-width:240px">
          <label class="form-label small text-muted">Buscar telefone / email</label>
          <input class="form-control form-control-sm" v-model="filters.search"
                 @keyup.enter="reload()" placeholder="Digite parte do identificador" />
        </div>
        <div>
          <button class="btn btn-sm btn-primary" @click="reload()">
            <i class="ti ti-search me-1"></i>Filtrar
          </button>
        </div>
      </div>
    </div>

    <!-- Tabela -->
    <div class="card" style="border-radius:14px">
      <div class="card-body p-0">
        <table class="table card-table table-vcenter mb-0">
          <thead>
            <tr>
              <th>Identificador</th>
              <th>Canal</th>
              <th>Motivo</th>
              <th>Adicionado</th>
              <th class="text-end">Ações</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="5" class="text-center text-muted py-4">
                <span class="spinner-border spinner-border-sm me-2"></span>Carregando...
              </td>
            </tr>
            <tr v-else-if="!items.length">
              <td colspan="5" class="text-center text-muted py-4">
                <i class="ti ti-shield-check d-block mb-2" style="font-size:1.5rem"></i>
                Nenhum opt-out registrado ainda.
              </td>
            </tr>
            <tr v-for="row in items" :key="row.id">
              <td style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">{{ row.identifier }}</td>
              <td><span class="badge" :class="channelBadge(row.channel)">{{ channelLabel(row.channel) }}</span></td>
              <td class="small text-muted">{{ row.reason }}</td>
              <td class="small text-muted">{{ formatDate(row.created_at) }}</td>
              <td class="text-end">
                <button class="btn btn-sm btn-ghost-danger" @click="confirmRemoveId = row.id">
                  <i class="ti ti-trash"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="pagination.last_page > 1" class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">
          Página {{ pagination.current_page }} de {{ pagination.last_page }} ({{ pagination.total }} no total)
        </span>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary" :disabled="pagination.current_page <= 1"
                  @click="reload(pagination.current_page - 1)">
            <i class="ti ti-chevron-left"></i>
          </button>
          <button class="btn btn-sm btn-outline-secondary" :disabled="pagination.current_page >= pagination.last_page"
                  @click="reload(pagination.current_page + 1)">
            <i class="ti ti-chevron-right"></i>
          </button>
        </div>
      </div>
    </div>

    <!-- Modal adicionar manual -->
    <div v-if="showAddModal" class="modal modal-blur fade show" style="display:block" tabindex="-1">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Adicionar opt-out</h5>
            <button type="button" class="btn-close" @click="closeAdd"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Canal *</label>
              <select class="form-select" v-model="newOptOut.channel">
                <option value="all">Todos (global)</option>
                <option value="sms">SMS</option>
                <option value="voice">Voz</option>
                <option value="email">Email</option>
                <option value="whatsapp">WhatsApp</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Identificador (telefone ou email) *</label>
              <input class="form-control" v-model="newOptOut.identifier"
                     placeholder="+5511999990001 ou usuario@exemplo.com" />
            </div>
            <div class="mb-3">
              <label class="form-label">Motivo (opcional)</label>
              <input class="form-control" v-model="newOptOut.reason"
                     placeholder="ex: solicitação_manual" maxlength="50" />
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-link link-secondary" @click="closeAdd">Cancelar</button>
            <button class="btn btn-primary" :disabled="isSaving || !newOptOut.identifier" @click="addOptOut">
              <span v-if="isSaving" class="spinner-border spinner-border-sm me-2"></span>
              Adicionar
            </button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="showAddModal" class="modal-backdrop fade show"></div>

    <ConfirmModal
      :visible="confirmRemoveId !== null"
      title="Remover opt-out"
      message="Remover este registro permite enviar mensagens novamente para este contato. Confirmar?"
      confirm-text="Remover"
      :loading="isRemoving"
      @confirm="removeOptOut"
      @cancel="confirmRemoveId = null"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'

interface OptOut {
  id: number
  channel: string
  identifier: string
  reason: string
  created_at: string
}

const { get, post, del } = useApi()
const toast = useToast()

const items = ref<OptOut[]>([])
const loading = ref(false)
const pagination = reactive({ current_page: 1, last_page: 1, per_page: 30, total: 0 })
const filters = reactive({ channel: '', search: '' })
const importResult = ref<{ imported: number; skipped: number; errors?: string[] } | null>(null)
const showAddModal = ref(false)
const isSaving = ref(false)
const confirmRemoveId = ref<number | null>(null)
const isRemoving = ref(false)
const newOptOut = reactive({ channel: 'all', identifier: '', reason: '' })

async function reload(page = 1) {
  loading.value = true
  try {
    const res = await get<any>('/messaging/opt-outs', {
      page,
      channel: filters.channel || undefined,
      search: filters.search || undefined,
    })
    items.value = res?.data ?? []
    if (res?.meta) Object.assign(pagination, res.meta)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao carregar opt-outs')
  } finally {
    loading.value = false
  }
}

async function addOptOut() {
  if (!newOptOut.identifier) return
  isSaving.value = true
  try {
    await post('/messaging/opt-outs', {
      channel: newOptOut.channel,
      identifier: newOptOut.identifier,
      reason: newOptOut.reason || undefined,
    })
    toast.success('Opt-out adicionado')
    closeAdd()
    await reload()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao adicionar')
  } finally {
    isSaving.value = false
  }
}

async function removeOptOut() {
  if (confirmRemoveId.value === null) return
  isRemoving.value = true
  try {
    await del(`/messaging/opt-outs/${confirmRemoveId.value}`)
    toast.success('Opt-out removido')
    confirmRemoveId.value = null
    await reload(pagination.current_page)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao remover')
  } finally {
    isRemoving.value = false
  }
}

async function onImportFile(e: Event) {
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  if (!file) return
  const fd = new FormData()
  fd.append('file', file)
  try {
    const res = await post<any>('/messaging/opt-outs/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    importResult.value = res
    toast.success(`${res.imported} opt-outs importados`)
    await reload()
  } catch (err: any) {
    toast.error(err?.response?.data?.message ?? 'Erro ao importar')
  } finally {
    target.value = '' // reset
  }
}

async function downloadCsv() {
  // Endpoint stream — abre direto pra triggerar download.
  window.open('/api/v1/messaging/opt-outs/export', '_blank')
}

function closeAdd() {
  showAddModal.value = false
  newOptOut.channel = 'all'
  newOptOut.identifier = ''
  newOptOut.reason = ''
}

function channelLabel(c: string): string {
  return ({ sms: 'SMS', voice: 'Voz', email: 'Email', whatsapp: 'WhatsApp', all: 'Todos' } as Record<string,string>)[c] ?? c
}
function channelBadge(c: string): string {
  return ({
    sms:      'bg-green',
    voice:    'bg-pink',
    email:    'bg-blue',
    whatsapp: 'bg-green',
    all:      'bg-secondary',
  } as Record<string,string>)[c] ?? 'bg-secondary'
}
function formatDate(s: string): string {
  if (!s) return '—'
  try { return new Date(s).toLocaleString('pt-BR') } catch { return s }
}

onMounted(() => reload())
</script>
