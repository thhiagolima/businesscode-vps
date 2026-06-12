<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Webhooks</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">
          Receba notificações HTTP sobre eventos da sua conta em tempo real.
        </span>
      </div>
      <button class="btn btn-primary" @click="openCreateModal">
        <i class="ti ti-plus me-1"></i>
        Novo webhook
      </button>
    </div>

    <div class="card" style="border-radius:14px">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title">Webhooks configurados</h3>
        <button class="btn btn-sm btn-ghost-secondary" @click="loadWebhooks" :disabled="loading">
          <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-refresh me-1"></i>
          Atualizar
        </button>
      </div>

      <div v-if="loading && webhooks.length === 0" class="card-body text-center py-5">
        <span class="spinner-border spinner-border-sm"></span>
      </div>

      <div v-else-if="webhooks.length === 0" class="card-body text-center py-5">
        <i class="ti ti-webhook" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
        <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">Nenhum webhook configurado</h3>
        <p style="font-size:0.85rem;color:var(--bc-text-muted)">
          Crie seu primeiro webhook para receber notificações de eventos.
        </p>
      </div>

      <div v-else class="table-responsive">
        <table class="table card-table table-vcenter">
          <thead>
            <tr>
              <th>URL</th>
              <th>Eventos</th>
              <th>Status</th>
              <th>Última entrega</th>
              <th class="text-end">Ações</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="w in webhooks" :key="w.id">
              <td>
                <span :title="w.url" style="font-family:'JetBrains Mono',monospace;font-size:0.78rem">
                  {{ truncate(w.url, 50) }}
                </span>
              </td>
              <td>
                <span
                  v-for="ev in (w.events || []).slice(0, 4)"
                  :key="ev"
                  class="badge bg-blue-lt me-1 mb-1"
                  style="font-family:'JetBrains Mono',monospace;font-size:0.65rem"
                >{{ ev }}</span>
                <span v-if="(w.events || []).length > 4" class="badge bg-secondary-lt" style="font-size:0.65rem">
                  +{{ w.events.length - 4 }}
                </span>
              </td>
              <td>
                <label class="form-check form-switch m-0">
                  <input
                    type="checkbox"
                    class="form-check-input"
                    :checked="w.is_active"
                    :disabled="toggling === w.id"
                    @change="toggleActive(w)"
                  />
                </label>
              </td>
              <td style="font-size:0.78rem">
                <div v-if="w.last_delivery_at">
                  {{ formatDate(w.last_delivery_at) }}
                  <span
                    v-if="w.last_delivery_status"
                    class="badge ms-1"
                    :class="statusBadgeClass(w.last_delivery_status)"
                    style="font-size:0.65rem"
                  >{{ w.last_delivery_status }}</span>
                </div>
                <span v-else class="text-muted">Nunca</span>
              </td>
              <td class="text-end">
                <button
                  class="btn btn-sm btn-outline-primary me-1"
                  @click="testWebhook(w)"
                  :disabled="testing === w.id"
                  title="Disparar evento de teste"
                >
                  <span v-if="testing === w.id" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="ti ti-bolt me-1"></i>
                  Testar
                </button>
                <button
                  class="btn btn-sm btn-ghost-secondary me-1"
                  @click="viewDeliveries(w)"
                  title="Ver entregas"
                >
                  <i class="ti ti-history"></i>
                </button>
                <button
                  class="btn btn-sm btn-ghost-secondary me-1"
                  @click="openEditModal(w)"
                  title="Editar"
                >
                  <i class="ti ti-edit"></i>
                </button>
                <button
                  class="btn btn-sm btn-outline-danger"
                  @click="askDelete(w)"
                  title="Excluir"
                >
                  <i class="ti ti-trash"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Inline "Last test result" panel -->
    <div v-if="lastTestResult" class="card mt-4" style="border-radius:14px">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title">
          <i class="ti ti-bolt me-1"></i>
          Resultado do último teste
        </h3>
        <button class="btn btn-sm btn-ghost-secondary" @click="lastTestResult = null">
          <i class="ti ti-x"></i>
        </button>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-sm-4">
            <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase">Status HTTP</div>
            <div :class="lastTestResult.ok ? 'text-success' : 'text-danger'" style="font-size:1.4rem;font-weight:700">
              {{ lastTestResult.response_status ?? 'ERR' }}
            </div>
          </div>
          <div class="col-sm-4">
            <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase">Duração</div>
            <div style="font-size:1.4rem;font-weight:700">{{ lastTestResult.duration_ms }}ms</div>
          </div>
          <div class="col-sm-4">
            <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase">Resultado</div>
            <div :class="lastTestResult.ok ? 'text-success' : 'text-danger'" style="font-size:1.4rem;font-weight:700">
              {{ lastTestResult.ok ? 'OK' : 'Falhou' }}
            </div>
          </div>
        </div>
        <div v-if="lastTestResult.error_message" class="alert alert-danger mt-3" style="font-size:0.82rem">
          {{ lastTestResult.error_message }}
        </div>
        <div v-if="lastTestResult.response_body" class="mt-3">
          <label class="form-label" style="font-size:0.78rem">Resposta (preview)</label>
          <pre style="background:var(--bc-surface-container,#1a1e37);color:#dee0ff;padding:0.75rem;border-radius:8px;font-size:0.74rem;max-height:200px;overflow:auto;margin:0"><code>{{ lastTestResult.response_body }}</code></pre>
        </div>
      </div>
    </div>

    <!-- Create / Edit Modal -->
    <div
      class="modal modal-blur fade"
      :class="{ show: showFormModal }"
      :style="{ display: showFormModal ? 'block' : 'none' }"
      tabindex="-1"
    >
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h3 class="modal-title">
              <i class="ti ti-webhook me-1"></i>
              {{ editingId ? 'Editar webhook' : 'Novo webhook' }}
            </h3>
            <button type="button" class="btn-close" @click="closeFormModal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label required">URL</label>
              <input
                class="form-control"
                :class="{ 'is-invalid': formErrors.url }"
                v-model="form.url"
                placeholder="https://api.exemplo.com/webhook"
                type="url"
              />
              <div v-if="formErrors.url" class="invalid-feedback">{{ formErrors.url }}</div>
              <div v-else class="form-hint">A URL deve usar HTTPS.</div>
            </div>

            <div class="mb-3">
              <label class="form-label required">Eventos</label>
              <div :class="{ 'is-invalid': formErrors.events }">
                <div v-for="grp in eventGroups" :key="grp.label" class="mb-2">
                  <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;font-weight:600;margin-bottom:0.25rem">{{ grp.label }}</div>
                  <label v-for="ev in grp.events" :key="ev" class="form-check form-check-inline">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      :value="ev"
                      v-model="form.events"
                    />
                    <span class="form-check-label" style="font-family:'JetBrains Mono',monospace;font-size:0.72rem">{{ ev }}</span>
                  </label>
                </div>
              </div>
              <div v-if="formErrors.events" class="invalid-feedback d-block">{{ formErrors.events }}</div>
            </div>

            <div class="mb-3">
              <label class="form-check form-switch">
                <input class="form-check-input" type="checkbox" v-model="form.is_active" />
                <span class="form-check-label">Ativo</span>
              </label>
            </div>

            <div v-if="!editingId">
              <div class="alert alert-info" style="font-size:0.82rem">
                <i class="ti ti-info-circle me-1"></i>
                Um segredo (32 hex chars) será gerado automaticamente para assinar as requisições com HMAC-SHA256.
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-link link-secondary" @click="closeFormModal">Cancelar</button>
            <button class="btn btn-primary" @click="submitForm" :disabled="saving">
              <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="ti ti-check me-1"></i>
              {{ editingId ? 'Salvar' : 'Criar webhook' }}
            </button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="showFormModal" class="modal-backdrop fade show"></div>

    <!-- Secret Modal (only on create) -->
    <div
      class="modal modal-blur fade"
      :class="{ show: !!createdSecret }"
      :style="{ display: createdSecret ? 'block' : 'none' }"
      tabindex="-1"
    >
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h3 class="modal-title">
              <i class="ti ti-key text-warning me-1"></i>
              Segredo do webhook
            </h3>
          </div>
          <div class="modal-body">
            <div class="alert alert-warning" role="alert">
              <i class="ti ti-alert-triangle me-2"></i>
              <strong>Este segredo só será exibido agora.</strong>
              Use-o para verificar a assinatura <code>X-Webhook-Signature</code> (HMAC-SHA256) nas requisições recebidas.
            </div>
            <label class="form-label">Segredo (plaintext)</label>
            <div class="input-group">
              <input
                type="text"
                class="form-control"
                style="font-family:'JetBrains Mono',monospace;font-size:0.82rem"
                :value="createdSecret"
                readonly
              />
              <button class="btn btn-primary" @click="copy(createdSecret || '')">
                <i class="ti ti-copy me-1"></i>
                Copiar
              </button>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-primary" @click="createdSecret = null">Já salvei, fechar</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="createdSecret" class="modal-backdrop fade show"></div>

    <!-- Deliveries Modal -->
    <div
      class="modal modal-blur fade"
      :class="{ show: !!deliveriesFor }"
      :style="{ display: deliveriesFor ? 'block' : 'none' }"
      tabindex="-1"
    >
      <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h3 class="modal-title">
              Entregas — webhook #{{ deliveriesFor?.id }}
            </h3>
            <button type="button" class="btn-close" @click="deliveriesFor = null"></button>
          </div>
          <div class="modal-body" style="max-height:65vh;overflow:auto">
            <div v-if="loadingDeliveries" class="text-center py-5">
              <span class="spinner-border spinner-border-sm"></span>
            </div>
            <div v-else-if="deliveries.length === 0" class="text-center py-5 text-muted">
              Nenhuma entrega registrada ainda.
            </div>
            <table v-else class="table table-vcenter">
              <thead>
                <tr>
                  <th>Data</th>
                  <th>Evento</th>
                  <th>Status</th>
                  <th>Duração</th>
                  <th>Tentativa</th>
                  <th>Erro</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in deliveries" :key="d.id">
                  <td style="font-size:0.78rem">{{ formatDate(d.fired_at) }}</td>
                  <td style="font-family:'JetBrains Mono',monospace;font-size:0.72rem">{{ d.event }}</td>
                  <td>
                    <span
                      class="badge"
                      :class="statusBadgeClass(d.response_status)"
                      style="font-size:0.7rem"
                    >{{ d.response_status ?? 'ERR' }}</span>
                  </td>
                  <td style="font-size:0.78rem">{{ d.duration_ms }}ms</td>
                  <td style="font-size:0.78rem">#{{ d.attempt_number }}</td>
                  <td style="font-size:0.72rem;color:var(--bc-text-muted)">
                    {{ truncate(d.error_message || '', 60) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="modal-footer">
            <button class="btn" @click="deliveriesFor = null">Fechar</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="deliveriesFor" class="modal-backdrop fade show"></div>

    <!-- Confirm delete -->
    <ConfirmModal
      :visible="!!webhookToDelete"
      title="Excluir webhook?"
      :message="`O webhook '${webhookToDelete?.url ?? ''}' e todo o histórico de entregas serão removidos permanentemente.`"
      confirm-text="Excluir"
      confirm-class="btn-danger"
      :loading="deleting !== null"
      @confirm="confirmDelete"
      @cancel="webhookToDelete = null"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'

type Webhook = {
  id: number
  url: string
  events: string[]
  is_active: boolean
  has_secret?: boolean
  last_delivery_at?: string | null
  last_delivery_status?: number | null
  created_at?: string
  updated_at?: string
}

type Delivery = {
  id: number
  event: string
  response_status: number | null
  duration_ms: number | null
  attempt_number: number
  error_message: string | null
  fired_at: string
}

type TestResult = {
  delivery_id: number
  ok: boolean
  response_status: number | null
  duration_ms: number
  response_body: string | null
  error_message: string | null
}

const { get, post, put, del } = useApi()
const toast = useToast()

const webhooks = ref<Webhook[]>([])
const allEvents = ref<string[]>([])
const loading = ref(false)
const saving = ref(false)
const deleting = ref<number | null>(null)
const toggling = ref<number | null>(null)
const testing = ref<number | null>(null)

const showFormModal = ref(false)
const editingId = ref<number | null>(null)
const form = ref<{ url: string; events: string[]; is_active: boolean }>({
  url: '',
  events: [],
  is_active: true,
})
const formErrors = ref<Record<string, string>>({})

const createdSecret = ref<string | null>(null)
const webhookToDelete = ref<Webhook | null>(null)

const deliveriesFor = ref<Webhook | null>(null)
const deliveries = ref<Delivery[]>([])
const loadingDeliveries = ref(false)

const lastTestResult = ref<TestResult | null>(null)

const eventGroups = computed(() => {
  const buckets: Record<string, string[]> = {
    'Mensagens': [],
    'Voz': [],
    'Email': [],
    'Cobrança': [],
    'Opt-out': [],
    'Outros': [],
  }
  for (const ev of allEvents.value) {
    if (ev.startsWith('call.')) buckets['Voz'].push(ev)
    else if (ev.startsWith('email.')) buckets['Email'].push(ev)
    else if (ev.startsWith('message.')) buckets['Mensagens'].push(ev)
    else if (ev.startsWith('billing.')) buckets['Cobrança'].push(ev)
    else if (ev.startsWith('optout.')) buckets['Opt-out'].push(ev)
    else buckets['Outros'].push(ev)
  }
  return Object.entries(buckets)
    .filter(([, list]) => list.length > 0)
    .map(([label, events]) => ({ label, events }))
})

function truncate(s: string, max: number): string {
  if (!s) return ''
  return s.length > max ? s.slice(0, max) + '…' : s
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  return d.toLocaleString('pt-BR', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function statusBadgeClass(status: number | null | undefined): string {
  if (!status) return 'bg-danger-lt'
  if (status >= 200 && status < 300) return 'bg-success-lt'
  if (status >= 400) return 'bg-danger-lt'
  return 'bg-warning-lt'
}

async function loadEvents() {
  try {
    const res = await get<string[]>('/webhooks/events')
    allEvents.value = Array.isArray(res) ? res : []
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao carregar lista de eventos')
  }
}

async function loadWebhooks() {
  loading.value = true
  try {
    const res = await get<Webhook[]>('/webhooks/outbound')
    webhooks.value = Array.isArray(res) ? res : []
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao carregar webhooks')
  } finally {
    loading.value = false
  }
}

function openCreateModal() {
  editingId.value = null
  form.value = { url: '', events: [], is_active: true }
  formErrors.value = {}
  showFormModal.value = true
}

function openEditModal(w: Webhook) {
  editingId.value = w.id
  form.value = {
    url: w.url,
    events: [...(w.events || [])],
    is_active: w.is_active,
  }
  formErrors.value = {}
  showFormModal.value = true
}

function closeFormModal() {
  showFormModal.value = false
}

function validateForm(): boolean {
  const errors: Record<string, string> = {}
  if (!form.value.url.trim()) {
    errors.url = 'Informe a URL.'
  } else if (!/^https:\/\//i.test(form.value.url.trim())) {
    errors.url = 'A URL deve começar com https://'
  }
  if (!form.value.events.length) {
    errors.events = 'Selecione ao menos um evento.'
  }
  formErrors.value = errors
  return Object.keys(errors).length === 0
}

async function submitForm() {
  if (!validateForm()) return
  saving.value = true
  try {
    if (editingId.value) {
      await put(`/webhooks/outbound/${editingId.value}`, {
        url: form.value.url.trim(),
        events: form.value.events,
        is_active: form.value.is_active,
      })
      toast.success('Webhook atualizado')
    } else {
      const created = await post<{ id: number; secret?: string }>('/webhooks/outbound', {
        url: form.value.url.trim(),
        events: form.value.events,
        is_active: form.value.is_active,
      })
      toast.success('Webhook criado')
      if (created?.secret) {
        createdSecret.value = created.secret
      }
    }
    showFormModal.value = false
    await loadWebhooks()
  } catch (e: any) {
    const data = e?.response?.data
    if (data?.errors) {
      const flat: Record<string, string> = {}
      for (const k of Object.keys(data.errors)) {
        flat[k] = Array.isArray(data.errors[k]) ? data.errors[k][0] : String(data.errors[k])
      }
      formErrors.value = flat
    }
    toast.error(data?.message ?? 'Erro ao salvar webhook')
  } finally {
    saving.value = false
  }
}

async function toggleActive(w: Webhook) {
  toggling.value = w.id
  try {
    await put(`/webhooks/outbound/${w.id}`, { is_active: !w.is_active })
    w.is_active = !w.is_active
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao alterar status')
  } finally {
    toggling.value = null
  }
}

async function testWebhook(w: Webhook) {
  testing.value = w.id
  lastTestResult.value = null
  try {
    const res = await post<TestResult>(`/webhooks/outbound/${w.id}/test`, {})
    lastTestResult.value = res
    if (res?.ok) {
      toast.success(`Teste OK — HTTP ${res.response_status} em ${res.duration_ms}ms`)
    } else {
      toast.error(`Teste falhou — ${res?.response_status ?? 'erro de conexão'}`)
    }
    await loadWebhooks()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao testar webhook')
  } finally {
    testing.value = null
  }
}

async function viewDeliveries(w: Webhook) {
  deliveriesFor.value = w
  deliveries.value = []
  loadingDeliveries.value = true
  try {
    const res = await get<any>(`/webhooks/outbound/${w.id}/deliveries`)
    // Pagination payload from Laravel — items live on .data
    const items = Array.isArray(res) ? res : (res?.data ?? [])
    deliveries.value = items
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao carregar entregas')
  } finally {
    loadingDeliveries.value = false
  }
}

function askDelete(w: Webhook) {
  webhookToDelete.value = w
}

async function confirmDelete() {
  if (!webhookToDelete.value) return
  const w = webhookToDelete.value
  deleting.value = w.id
  try {
    await del(`/webhooks/outbound/${w.id}`)
    toast.success('Webhook excluído')
    webhookToDelete.value = null
    await loadWebhooks()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao excluir webhook')
  } finally {
    deleting.value = null
  }
}

async function copy(text: string) {
  if (!text) return
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    }
    toast.success('Copiado')
  } catch {
    toast.error('Não foi possível copiar')
  }
}

onMounted(() => {
  loadEvents()
  loadWebhooks()
})
</script>

<style scoped>
.form-check-inline {
  margin-right: 1rem;
  margin-bottom: 0.25rem;
}
</style>
