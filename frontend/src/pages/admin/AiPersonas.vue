<template>
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Personas IA</h2>
      <span style="font-size:0.82rem;color:var(--bc-text-muted)">Aprovação e gerenciamento</span>
    </div>
    <div class="d-flex gap-2">
    </div>
  </div>
  <div class="card" style="border-radius:14px">
    <div class="card-header">
      <div class="card-title">Personas</div>
      <div class="card-options">
        <select class="form-select form-select-sm" v-model="filterStatus" @change="load" style="width:180px">
          <option value="">Todos os status</option>
          <option value="pending_approval">Pendentes</option>
          <option value="approved">Aprovadas</option>
          <option value="rejected">Rejeitadas</option>
        </select>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table card-table table-vcenter">
        <thead><tr>
          <th>Tenant</th><th>Nome do bot</th><th>Tom</th><th>Status</th><th>Atualizada</th><th></th>
        </tr></thead>
        <tbody>
          <tr v-if="!items.length"><td colspan="6" class="text-center text-muted py-4">Nenhuma persona encontrada</td></tr>
          <tr v-for="p in items" :key="p.id">
            <td class="fw-bold">{{ p.tenant?.name ?? `#${p.tenant_id}` }}</td>
            <td>{{ p.bot_name }}</td>
            <td>{{ toneLabel(p.tone) }}</td>
            <td><span :class="['badge', statusCls(p.status)]">{{ statusLabel(p.status) }}</span></td>
            <td class="text-muted">{{ formatDate(p.updated_at) }}</td>
            <td>
              <button class="btn btn-sm btn-ghost-primary" @click="openDetail(p)">Revisar</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Detail Modal -->
  <div v-if="selected" class="modal modal-blur fade show" style="display:block" tabindex="-1" @click.self="selected = null">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" @click.self="selected = null">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Persona: {{ selected.bot_name }} — {{ selected.tenant?.name }}</h5>
          <button type="button" class="btn-close" @click="selected = null"></button>
        </div>
        <div class="modal-body">
          <table class="table table-borderless">
            <tr><td class="text-muted" style="width:150px">Nome do bot</td><td class="fw-bold">{{ selected.bot_name }}</td></tr>
            <tr><td class="text-muted">Tom</td><td>{{ toneLabel(selected.tone) }}</td></tr>
            <tr><td class="text-muted">Empresa</td><td>{{ selected.company_name }}</td></tr>
            <tr><td class="text-muted">Produtos/Serviços</td><td style="white-space:pre-wrap">{{ selected.products_services }}</td></tr>
            <tr><td class="text-muted">Regras de negócio</td><td style="white-space:pre-wrap">{{ selected.business_rules }}</td></tr>
            <tr v-if="selected.special_instructions"><td class="text-muted">Instruções especiais</td><td style="white-space:pre-wrap">{{ selected.special_instructions }}</td></tr>
            <tr v-if="selected.working_hours"><td class="text-muted">Horário</td><td>{{ selected.working_hours }}</td></tr>
          </table>
          <div v-if="selected.status === 'rejected'" class="alert alert-danger">
            <strong>Motivo da rejeição:</strong> {{ selected.rejection_reason }}
          </div>
          <div v-if="showRejectForm" class="mt-3">
            <label class="form-label">Motivo da rejeição *</label>
            <textarea class="form-control" v-model="rejectReason" rows="2" maxlength="500" placeholder="Explique o motivo da rejeição"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-link link-secondary" @click="selected = null">Fechar</button>
          <template v-if="selected.status === 'pending_approval'">
            <button v-if="!showRejectForm" class="btn btn-outline-danger" @click="showRejectForm = true" :disabled="acting">Rejeitar</button>
            <button v-if="showRejectForm" class="btn btn-danger" @click="reject" :disabled="acting || !rejectReason.trim()">
              <span v-if="acting" class="spinner-border spinner-border-sm me-1"></span>Confirmar rejeição
            </button>
            <button class="btn btn-success" @click="approve" :disabled="acting">
              <span v-if="acting" class="spinner-border spinner-border-sm me-1"></span>Aprovar
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
  <div v-if="selected" class="modal-backdrop fade show"></div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, patch } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const filterStatus = ref('pending_approval')
const selected = ref<any>(null)
const showRejectForm = ref(false)
const rejectReason = ref('')
const acting = ref(false)

async function load() {
  try {
    const params: Record<string, any> = {}
    if (filterStatus.value) params.status = filterStatus.value
    const res = await get<any>('/admin/ai-personas', params)
    items.value = res?.data ?? res ?? []
  } catch { /* silent */ }
}

function openDetail(p: any) {
  selected.value = p
  showRejectForm.value = false
  rejectReason.value = ''
}

async function approve() {
  acting.value = true
  try {
    await patch(`/admin/ai-personas/${selected.value.id}/approve`, {})
    toast.success('Persona aprovada')
    selected.value = null
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') }
  finally { acting.value = false }
}

async function reject() {
  acting.value = true
  try {
    await patch(`/admin/ai-personas/${selected.value.id}/reject`, { reason: rejectReason.value })
    toast.success('Persona rejeitada')
    selected.value = null
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') }
  finally { acting.value = false }
}

function toneLabel(t: string) { return { formal: 'Formal', casual: 'Casual', friendly: 'Amigável' }[t] ?? t }
function statusLabel(s: string) { return { draft: 'Rascunho', pending_approval: 'Pendente', approved: 'Aprovada', rejected: 'Rejeitada' }[s] ?? s }
function statusCls(s: string) { return { draft: 'bg-secondary', pending_approval: 'bg-warning text-dark', approved: 'bg-success', rejected: 'bg-danger' }[s] ?? 'bg-secondary' }
function formatDate(d: string) { return d ? new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—' }

onMounted(load)
</script>
