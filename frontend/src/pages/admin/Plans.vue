<template>
  <div>
    <!-- Inline header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Planos</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">Gerencie os planos disponíveis para tenants</span>
      </div>
      <button class="btn btn-primary" style="border-radius:8px" @click="openCreate">
        <i class="ti ti-plus me-1"></i>Novo plano
      </button>
    </div>

    <div class="card" style="border-radius:14px">
      <!-- Loading -->
      <TableSkeleton v-if="isLoading" :rows="5" :cols="7" />

      <!-- Empty -->
      <EmptyState
        v-else-if="items.length === 0"
        icon="ti-template"
        title="Nenhum plano cadastrado"
        description="Crie o primeiro plano clicando no botão acima."
      />

      <!-- Table -->
      <div v-else class="table-responsive">
        <table class="table table-vcenter table-hover card-table table-clickable">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Preço mensal</th>
              <th>Saldo incluso</th>
              <th>Contatos</th>
              <th>Campanhas</th>
              <th>Status</th>
              <th class="w-1"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in items" :key="p.id">
              <td>
                <div class="fw-medium">{{ p.name }}</div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:0.78rem;color:var(--bc-text-muted)">{{ p.slug }}</div>
              </td>
              <td style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">R$ {{ Number(p.price_monthly).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}</td>
              <td style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">{{ brl(p.credits_included) }}</td>
              <td style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">{{ p.max_contacts === 0 ? '∞' : p.max_contacts?.toLocaleString('pt-BR') }}</td>
              <td style="font-family:'JetBrains Mono',monospace;font-size:0.85rem">{{ p.max_campaigns === 0 ? '∞' : p.max_campaigns?.toLocaleString('pt-BR') }}</td>
              <td>
                <StatusBadge :label="p.status === 'active' ? 'Ativo' : 'Inativo'" :status="p.status" dot />
              </td>
              <td class="text-end text-nowrap">
                <div class="d-flex gap-1 justify-content-end">
                  <button class="btn btn-sm btn-icon btn-ghost-primary" @click="openEdit(p)" title="Editar">
                    <i class="ti ti-pencil"></i>
                  </button>
                  <button class="btn btn-sm btn-icon btn-ghost-danger" @click="remove(p)" title="Excluir">
                    <i class="ti ti-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Create/Edit -->
    <div class="modal modal-blur fade" id="planModal" tabindex="-1" ref="modalEl">
      <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:14px">
          <div class="modal-header">
            <h5 class="modal-title" style="font-weight:700">{{ isEditing ? 'Editar plano' : 'Novo plano' }}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form @submit.prevent="save">
            <div class="modal-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label required">Nome</label>
                  <input v-model="form.name" type="text" class="form-control" style="border-radius:10px" required placeholder="Ex: Starter">
                </div>
                <div class="col-md-6">
                  <label class="form-label required">Slug</label>
                  <input v-model="form.slug" type="text" class="form-control" style="border-radius:10px" required placeholder="Ex: starter">
                </div>
                <div class="col-md-4">
                  <label class="form-label required">Preço mensal (R$)</label>
                  <input v-model.number="form.price_monthly" type="number" step="0.01" min="0" class="form-control" style="border-radius:10px" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label required">Saldo incluso (centavos)</label>
                  <input v-model.number="form.credits_included" type="number" min="0" class="form-control" style="border-radius:10px" required>
                  <div class="form-hint">Valor em centavos. Ex.: 10000 = R$ 100,00</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Status</label>
                  <select v-model="form.status" class="form-select" style="border-radius:10px">
                    <option value="active">Ativo</option>
                    <option value="inactive">Inativo</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Máx. contatos</label>
                  <input v-model.number="form.max_contacts" type="number" min="0" class="form-control" style="border-radius:10px" placeholder="0 = ilimitado">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Máx. campanhas</label>
                  <input v-model.number="form.max_campaigns" type="number" min="0" class="form-control" style="border-radius:10px" placeholder="0 = ilimitado">
                </div>

                <div class="col-12"><hr class="my-1"><div class="text-muted small mb-1">Taxas de excedente (centavos por unidade enviada)</div></div>
                <div class="col-md-3">
                  <label class="form-label">SMS</label>
                  <input v-model.number="form.overage_rate_sms" type="number" step="0.01" min="0" class="form-control" style="border-radius:10px">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Voz</label>
                  <input v-model.number="form.overage_rate_voice" type="number" step="0.01" min="0" class="form-control" style="border-radius:10px">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Email</label>
                  <input v-model.number="form.overage_rate_email" type="number" step="0.01" min="0" class="form-control" style="border-radius:10px">
                </div>
                <div class="col-md-3">
                  <label class="form-label">IA</label>
                  <input v-model.number="form.overage_rate_ai" type="number" step="0.01" min="0" class="form-control" style="border-radius:10px">
                </div>

                <div class="col-12">
                  <label class="form-label">Features (JSON)</label>
                  <textarea v-model="featuresJson" class="form-control" style="border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:0.85rem" rows="3" placeholder='["feature1","feature2"]'></textarea>
                  <div v-if="featuresError" class="text-danger small mt-1">JSON inválido</div>
                </div>
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
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref, computed, nextTick } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import { brl } from '@/utils/currency'
import { Modal } from 'bootstrap'

const { get, post, put, del } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const isLoading = ref(false)
const isSaving = ref(false)
const modalEl = ref<HTMLElement | null>(null)
let bsModal: any = null

const defaultForm = () => ({
  id: null as number | null,
  name: '',
  slug: '',
  price_monthly: 0,
  credits_included: 0,
  max_contacts: 0,
  max_campaigns: 0,
  overage_rate_sms: 1,
  overage_rate_voice: 5,
  overage_rate_email: 2,
  overage_rate_ai: 10,
  features: [] as any[],
  status: 'active',
})

const form = ref(defaultForm())
const featuresJson = ref('[]')
const isEditing = computed(() => form.value.id !== null)

const featuresError = computed(() => {
  try {
    JSON.parse(featuresJson.value)
    return false
  } catch {
    return true
  }
})

const load = async () => {
  isLoading.value = true
  try {
    items.value = await get<any[]>('/admin/plans')
  } finally {
    isLoading.value = false
  }
}

function getModal() {
  if (!bsModal && modalEl.value) {
    bsModal = new Modal(modalEl.value)
  }
  return bsModal
}

function openCreate() {
  form.value = defaultForm()
  featuresJson.value = '[]'
  nextTick(() => getModal()?.show())
}

function openEdit(plan: any) {
  form.value = {
    id: plan.id,
    name: plan.name ?? '',
    slug: plan.slug ?? '',
    price_monthly: Number(plan.price_monthly) || 0,
    credits_included: Number(plan.credits_included) || 0,
    max_contacts: Number(plan.max_contacts) || 0,
    max_campaigns: Number(plan.max_campaigns) || 0,
    overage_rate_sms: Number(plan.overage_rate_sms) || 0,
    overage_rate_voice: Number(plan.overage_rate_voice) || 0,
    overage_rate_email: Number(plan.overage_rate_email) || 0,
    overage_rate_ai: Number(plan.overage_rate_ai) || 0,
    features: plan.features ?? [],
    status: plan.status ?? 'active',
  }
  featuresJson.value = JSON.stringify(form.value.features, null, 2)
  nextTick(() => getModal()?.show())
}

async function save() {
  if (featuresError.value) {
    toast.error('O campo features contém JSON inválido.')
    return
  }

  isSaving.value = true
  try {
    const payload = {
      ...form.value,
      features: JSON.parse(featuresJson.value),
    }
    delete (payload as any).id

    if (isEditing.value) {
      await put(`/admin/plans/${form.value.id}`, payload)
      toast.success('Plano atualizado com sucesso')
    } else {
      await post('/admin/plans', payload)
      toast.success('Plano criado com sucesso')
    }

    getModal()?.hide()
    await load()
  } catch (e: any) {
    const msg = e?.response?.data?.message || (isEditing.value ? 'Erro ao atualizar plano' : 'Erro ao criar plano')
    toast.error(msg)
  } finally {
    isSaving.value = false
  }
}

async function remove(plan: any) {
  if (!confirm(`Excluir o plano "${plan.name}"? Esta ação não pode ser desfeita.`)) return
  try {
    await del(`/admin/plans/${plan.id}`)
    toast.success('Plano excluído')
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao excluir plano')
  }
}

onMounted(load)
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
