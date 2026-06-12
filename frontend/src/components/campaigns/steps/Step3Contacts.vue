<template>
  <div>
    <div class="mb-4">
      <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column flex-md-row gap-2">
        <label class="form-selectgroup-item flex-fill">
          <input type="radio" class="form-selectgroup-input" value="list" v-model="source">
          <span class="form-selectgroup-label d-flex align-items-center gap-2">
            <i class="ti ti-list text-primary" style="font-size:1.3rem"></i>
            <span>
              <span class="d-block fw-medium">Lista existente</span>
              <span class="d-block text-muted small">Selecione uma lista de contatos salva</span>
            </span>
          </span>
        </label>
        <label class="form-selectgroup-item flex-fill">
          <input type="radio" class="form-selectgroup-input" value="file" v-model="source">
          <span class="form-selectgroup-label d-flex align-items-center gap-2">
            <i class="ti ti-file-upload text-green" style="font-size:1.3rem"></i>
            <span>
              <span class="d-block fw-medium">Upload de arquivo</span>
              <span class="d-block text-muted small">CSV com números (uso único)</span>
            </span>
          </span>
        </label>
        <label class="form-selectgroup-item flex-fill">
          <input type="radio" class="form-selectgroup-input" value="manual" v-model="source">
          <span class="form-selectgroup-label d-flex align-items-center gap-2">
            <i class="ti ti-keyboard text-orange" style="font-size:1.3rem"></i>
            <span>
              <span class="d-block fw-medium">Digitar números</span>
              <span class="d-block text-muted small">Cole ou digite um por linha</span>
            </span>
          </span>
        </label>
      </div>
    </div>

    <!-- OPÇÃO 1: Lista existente -->
    <div v-if="source === 'list'">
      <label class="form-label">Lista de contatos</label>
      <div v-if="loadingLists" class="d-flex align-items-center py-2">
        <span class="spinner-border spinner-border-sm me-2"></span>
        <span class="text-muted">Carregando listas...</span>
      </div>
      <template v-else>
        <select class="form-select" v-model="selectedId" @change="onSelectList">
          <option :value="null" disabled>Selecione uma lista...</option>
          <option v-for="list in lists" :key="list.id" :value="list.id">
            {{ list.name }} ({{ list.contact_count }} contatos)
          </option>
        </select>

        <!-- Preview card -->
        <div v-if="selectedList" class="card mt-3">
          <div class="card-body py-3">
            <div class="d-flex justify-content-between mb-2">
              <span class="fw-bold">{{ selectedList.name }}</span>
              <span class="badge" :class="selectedList.contact_count > 0 ? 'bg-azure' : 'bg-danger'">
                {{ selectedList.contact_count }} contatos
              </span>
            </div>
            <div v-if="loadingPreview" class="text-center py-2">
              <span class="spinner-border spinner-border-sm"></span>
            </div>
            <template v-else>
              <div v-for="c in previewContacts" :key="c.id" class="text-muted small">
                {{ maskPhone(c.phone) }} · {{ c.name ?? '—' }}
              </div>
              <div v-if="selectedList.contact_count > 5" class="text-muted small mt-1">
                ... e mais {{ selectedList.contact_count - 5 }}
              </div>
              <div v-if="selectedList.contact_count === 0" class="alert alert-warning mt-2 mb-0 py-2">
                <i class="ti ti-alert-triangle me-1"></i>Lista vazia. Adicione contatos antes de continuar.
              </div>
            </template>
          </div>
        </div>

        <!-- Criar nova lista inline -->
        <div class="mt-3">
          <button v-if="!showNewListForm" class="btn btn-outline-primary btn-sm" @click="showNewListForm = true">
            <i class="ti ti-plus me-1"></i> Criar nova lista
          </button>
          <div v-else class="input-group">
            <input class="form-control" v-model="newListName" placeholder="Nome da nova lista" @keyup.enter="createList">
            <button class="btn btn-primary" @click="createList" :disabled="!newListName || creatingList">
              <span v-if="creatingList" class="spinner-border spinner-border-sm"></span>
              <span v-else>Criar</span>
            </button>
            <button class="btn btn-outline-secondary" @click="showNewListForm = false">Cancelar</button>
          </div>
        </div>
      </template>
    </div>

    <!-- OPÇÃO 2: Upload CSV -->
    <div v-if="source === 'file'">
      <div class="card">
        <div class="card-body">
          <div v-if="!csvFile"
               class="border-2 border-dashed rounded p-4 text-center"
               style="border-color:#ccc;cursor:pointer"
               @click="($refs.fileInput as HTMLInputElement)?.click()"
               @dragover.prevent
               @drop.prevent="onDrop">
            <i class="ti ti-cloud-upload" style="font-size:2.5rem;color:#aaa"></i>
            <p class="text-muted mt-2 mb-1">Arraste um arquivo CSV ou clique para selecionar</p>
            <p class="text-muted small mb-0">Formato: um número de telefone por linha (com ou sem cabeçalho)</p>
            <input ref="fileInput" type="file" accept=".csv,.txt" style="display:none" @change="onFileSelect">
          </div>
          <div v-else>
            <div class="d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2">
                <i class="ti ti-file-text text-primary" style="font-size:1.5rem"></i>
                <div>
                  <div class="fw-medium">{{ csvFile.name }}</div>
                  <div class="text-muted small">{{ parsedPhones.length }} números detectados</div>
                </div>
              </div>
              <button class="btn btn-ghost-danger btn-sm" @click="clearFile">
                <i class="ti ti-x"></i>
              </button>
            </div>
            <!-- Preview dos primeiros números -->
            <div v-if="parsedPhones.length" class="mt-2 p-2 bg-light rounded small" style="max-height:120px;overflow-y:auto">
              <div v-for="(p, i) in parsedPhones.slice(0, 10)" :key="i" class="text-muted">
                {{ p }}
              </div>
              <div v-if="parsedPhones.length > 10" class="text-muted mt-1">... e mais {{ parsedPhones.length - 10 }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- OPÇÃO 3: Digitar números -->
    <div v-if="source === 'manual'">
      <div class="card">
        <div class="card-body">
          <label class="form-label">Números de telefone</label>
          <textarea class="form-control" rows="8" v-model="manualText"
                    placeholder="+5511999999999&#10;+5521988888888&#10;+5531977777777&#10;&#10;Um número por linha (formato E.164 com +)"></textarea>
          <div class="d-flex justify-content-between mt-2">
            <span class="text-muted small">{{ manualPhones.length }} números válidos detectados</span>
            <span v-if="manualInvalid > 0" class="text-danger small">{{ manualInvalid }} inválidos (ignorados)</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Estimativa de custo -->
    <div v-if="totalContacts > 0" class="card mt-3">
      <div class="card-body py-2 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
          <i class="ti ti-wallet text-primary"></i>
          <span class="small">
            Custo estimado:
            <strong v-if="pricingLoading"><span class="spinner-border spinner-border-sm"></span></strong>
            <strong v-else-if="pricingError" class="text-danger">— (tarifa indisponível)</strong>
            <strong v-else>{{ brl(estimatedCredits) }}</strong>
          </span>
        </div>
        <span class="badge bg-azure">{{ totalContacts.toLocaleString('pt-BR') }} destinatários</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import { brl } from '@/utils/currency'

interface ContactList { id: number; name: string; contact_count: number }
interface Contact { id: number; name?: string | null; phone?: string }

const props = defineProps<{
  channel: 'sms' | 'voice' | 'email' | 'whatsapp'
  modelValue: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
  'update:valid': [value: boolean]
  'update:adhocPhones': [value: string[]]
  'update:source': [value: 'list' | 'file' | 'manual']
  'update:contacts-count': [count: number]
}>()

const { get, post } = useApi()
const toast = useToast()

const source = ref<'list' | 'file' | 'manual'>('list')

// Preço unitário REAL do canal — sempre via /account/pricing (P0-03 fonte única).
const unitSaleCents = ref<number | null>(null)
const pricingLoading = ref(true)
const pricingError = ref(false)
async function loadPricing() {
  pricingLoading.value = true
  pricingError.value = false
  try {
    const res = await get<any>('/account/pricing')
    const list = Array.isArray(res) ? res : (res?.data ?? [])
    const match = list.find((p: any) => p.service === props.channel)
    if (!match) {
      pricingError.value = true
      unitSaleCents.value = null
    } else {
      unitSaleCents.value = Number(match.sale_cents)
    }
  } catch {
    pricingError.value = true
    unitSaleCents.value = null
  } finally {
    pricingLoading.value = false
  }
}
onMounted(() => { loadPricing() })
watch(() => props.channel, () => loadPricing())

// === Lista existente ===
const lists = ref<ContactList[]>([])
const selectedId = ref<number | null>(props.modelValue)
const loadingLists = ref(false)
const loadingPreview = ref(false)
const previewContacts = ref<Contact[]>([])
const showNewListForm = ref(false)
const newListName = ref('')
const creatingList = ref(false)

const selectedList = computed(() => lists.value.find(l => l.id === selectedId.value) ?? null)

// === Upload CSV ===
const csvFile = ref<File | null>(null)
const parsedPhones = ref<string[]>([])

// === Manual ===
const manualText = ref('')
const phoneRegex = /^\+?[1-9]\d{6,14}$/

function normalizePhone(phone: string): string | null {
  phone = phone.trim().replace(/[\s\-\(\)]/g, '')
  if (/^\+[1-9]\d{6,14}$/.test(phone)) return phone
  if (/^[1-9]\d{9,14}$/.test(phone)) return '+' + phone // assume missing +
  return null // invalid
}

const manualPhones = computed(() =>
  manualText.value.split('\n')
    .map(l => normalizePhone(l))
    .filter((p): p is string => p !== null)
)
const manualInvalid = computed(() => {
  const lines = manualText.value.split('\n').map(l => l.trim()).filter(l => l.length > 0)
  return lines.length - manualPhones.value.length
})

// === Computed totals ===
const totalContacts = computed(() => {
  if (source.value === 'list') return selectedList.value?.contact_count ?? 0
  if (source.value === 'file') return parsedPhones.value.length
  if (source.value === 'manual') return manualPhones.value.length
  return 0
})

const estimatedCredits = computed(() => totalContacts.value * (unitSaleCents.value ?? 0))

// === Validity ===
watch([source, selectedList, parsedPhones, manualPhones], () => {
  let valid = false
  let phones: string[] = []

  if (source.value === 'list') {
    valid = !!selectedList.value && selectedList.value.contact_count > 0
    emit('update:modelValue', selectedId.value)
    emit('update:adhocPhones', [])
    emit('update:contacts-count', selectedList.value?.contact_count ?? 0)
  } else if (source.value === 'file') {
    valid = parsedPhones.value.length > 0
    emit('update:modelValue', null)
    emit('update:adhocPhones', parsedPhones.value)
    emit('update:contacts-count', parsedPhones.value.length)
  } else if (source.value === 'manual') {
    valid = manualPhones.value.length > 0
    emit('update:modelValue', null)
    emit('update:adhocPhones', manualPhones.value)
    emit('update:contacts-count', manualPhones.value.length)
  }

  emit('update:valid', valid)
  emit('update:source', source.value)
}, { immediate: true, deep: true })

// === Lista methods ===
function maskPhone(phone?: string): string {
  if (!phone) return '—'
  if (phone.length < 8) return phone
  return phone.slice(0, -8) + '****-' + phone.slice(-4)
}

async function fetchLists() {
  loadingLists.value = true
  try {
    const res = await get<any>('/contact-lists')
    lists.value = Array.isArray(res) ? res : (res?.data ?? [])
  } catch { lists.value = [] }
  finally { loadingLists.value = false }
}

async function fetchPreview(listId: number) {
  loadingPreview.value = true
  previewContacts.value = []
  try {
    const res = await get<any>('/contacts', { contact_list_id: listId, per_page: 5 })
    previewContacts.value = Array.isArray(res) ? res : (res?.data ?? [])
  } catch {}
  finally { loadingPreview.value = false }
}

async function onSelectList() {
  emit('update:modelValue', selectedId.value)
  if (selectedId.value != null) {
    fetchPreview(selectedId.value)
    // Re-fetch the list to get a fresh contact_count (the cached value may be stale)
    try {
      const fresh = await get<any>(`/contact-lists/${selectedId.value}`)
      if (fresh?.contact_count !== undefined) {
        const list = lists.value.find(l => l.id === selectedId.value)
        if (list) list.contact_count = fresh.contact_count
      }
    } catch {} // silent — fall back to cached count
  }
}

async function createList() {
  if (!newListName.value) return
  creatingList.value = true
  try {
    const created = await post<any>('/contact-lists', { name: newListName.value })
    toast.success('Lista criada')
    newListName.value = ''
    showNewListForm.value = false
    await fetchLists()
    if (created?.id) {
      selectedId.value = created.id
      emit('update:modelValue', created.id)
      fetchPreview(created.id)
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao criar lista')
  } finally { creatingList.value = false }
}

// === File methods ===
function onFileSelect(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) processFile(file)
}

function onDrop(e: DragEvent) {
  const file = e.dataTransfer?.files?.[0]
  if (file) processFile(file)
}

function processFile(file: File) {
  csvFile.value = file
  const reader = new FileReader()
  reader.onload = () => {
    const text = reader.result as string
    const lines = text.split(/[\r\n]+/).map(l => l.trim())
    // Detect and skip header
    const first = lines[0] ?? ''
    const startIndex = phoneRegex.test(first.replace(/[";,]/g, '')) ? 0 : 1
    const phones: string[] = []
    for (let i = startIndex; i < lines.length; i++) {
      // Extract first column (CSV pode ter ; ou ,)
      const col = lines[i].split(/[;,]/)[0].replace(/["\s]/g, '')
      const normalized = normalizePhone(col)
      if (normalized) phones.push(normalized)
    }
    parsedPhones.value = phones
  }
  reader.readAsText(file)
}

function clearFile() {
  csvFile.value = null
  parsedPhones.value = []
}

// === Sync ===
watch(() => props.modelValue, (val) => {
  if (val !== selectedId.value) selectedId.value = val
})

onMounted(() => {
  fetchLists().then(() => {
    if (selectedId.value != null) fetchPreview(selectedId.value)
  })
})
</script>
