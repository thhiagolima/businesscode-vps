<template>
  <div
    class="modal modal-blur fade"
    id="modalImport"
    tabindex="-1"
    ref="modalEl"
    @hidden.bs.modal="resetState"
  >
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Importar CSV</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <!-- Step indicator -->
        <div class="px-3 pt-3">
          <div class="steps steps-counter steps-3">
            <span class="step-item" :class="{ active: step >= 1 }">Upload</span>
            <span class="step-item" :class="{ active: step >= 2 }">Preview</span>
            <span class="step-item" :class="{ active: step >= 3 }">Importando</span>
          </div>
        </div>

        <!-- Step 1: Upload -->
        <div v-if="step === 1" class="modal-body">
          <!-- Drag-drop zone -->
          <div
            class="border border-2 rounded p-4 text-center mb-3"
            :class="{ 'border-primary bg-primary-lt': isDragging, 'border-dashed': !isDragging }"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop"
            @click="fileInput?.click()"
            style="cursor: pointer; min-height: 120px; display: flex; flex-direction: column; justify-content: center; align-items: center;"
          >
            <input
              ref="fileInput"
              type="file"
              accept=".csv,.txt"
              class="d-none"
              @change="onFileSelect"
            />
            <template v-if="!file">
              <i class="ti ti-upload fs-1 text-muted mb-2"></i>
              <p class="text-muted mb-0">Arraste o arquivo CSV aqui ou clique para selecionar</p>
              <small class="text-muted">Tamanho maximo: 10MB</small>
            </template>
            <template v-else>
              <i class="ti ti-file-check fs-1 text-success mb-2"></i>
              <p class="mb-0 fw-bold">{{ file.name }}</p>
              <small class="text-muted">{{ formatSize(file.size) }}</small>
              <button
                class="btn btn-sm btn-ghost-danger mt-2"
                @click.stop="file = null"
              >
                Remover
              </button>
            </template>
          </div>

          <!-- Download template -->
          <div class="mb-3">
            <a href="#" class="text-primary" @click.prevent="downloadTemplate">
              <i class="ti ti-download me-1"></i>Download template CSV
            </a>
          </div>

          <!-- Select list -->
          <div class="mb-3">
            <label class="form-label">Lista de destino</label>
            <select class="form-select" v-model="selectedListId">
              <option :value="null" disabled>Selecione uma lista...</option>
              <option
                v-for="list in contactLists"
                :key="list.id"
                :value="list.id"
              >
                {{ list.name }} ({{ list.contact_count }} contatos)
              </option>
            </select>
          </div>
        </div>

        <!-- Step 2: Preview + Mapping -->
        <div v-if="step === 2" class="modal-body">
          <div class="table-responsive mb-3">
            <table class="table table-vcenter table-bordered">
              <thead>
                <tr>
                  <th v-for="(header, idx) in headers" :key="idx" class="p-1">
                    <select class="form-select form-select-sm" v-model="columnMapping[idx]">
                      <option value="ignore">Ignorar</option>
                      <option value="phone">Telefone</option>
                      <option value="name">Nome</option>
                      <option value="email">Email</option>
                    </select>
                    <small class="text-muted d-block mt-1 px-1">{{ header }}</small>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, rIdx) in previewRows" :key="rIdx">
                  <td v-for="(cell, cIdx) in row" :key="cIdx" class="text-muted small">
                    {{ cell }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="d-flex gap-3 text-muted small">
            <span><strong>{{ totalRows }}</strong> linhas no arquivo</span>
            <span><strong>{{ validPhoneCount }}</strong> telefones validos</span>
          </div>
        </div>

        <!-- Step 3: Importing + Progress -->
        <div v-if="step === 3" class="modal-body">
          <template v-if="!importStatus || importStatus.status === 'processing' || importStatus.status === 'pending'">
            <div class="text-center mb-3">
              <div class="spinner-border text-primary mb-3" role="status"></div>
              <p class="text-muted">Importando contatos...</p>
            </div>
            <div class="progress mb-2">
              <div
                class="progress-bar progress-bar-striped progress-bar-animated"
                :style="{ width: progressPercent + '%' }"
              ></div>
            </div>
            <div class="text-muted small text-center">
              {{ importStatus?.processed_rows ?? 0 }} / {{ importStatus?.total_rows ?? totalRows }} processados
            </div>
          </template>

          <template v-else-if="importStatus.status === 'completed'">
            <div class="alert alert-success">
              <i class="ti ti-check me-2"></i>
              {{ importStatus.processed_rows }} contatos importados com sucesso
            </div>
          </template>

          <template v-else-if="importStatus.status === 'failed'">
            <div class="alert alert-danger">
              <i class="ti ti-alert-circle me-2"></i>
              Erro na importacao: {{ importStatus.error_message }}
            </div>
          </template>
        </div>

        <!-- Footer -->
        <div class="modal-footer">
          <template v-if="step === 1">
            <button class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button
              class="btn btn-primary"
              :disabled="!file || !selectedListId"
              @click="goToStep2"
            >
              Proximo <i class="ti ti-arrow-right ms-1"></i>
            </button>
          </template>

          <template v-if="step === 2">
            <button class="btn btn-link link-secondary" @click="goBackToStep1">
              <i class="ti ti-arrow-left me-1"></i> Voltar
            </button>
            <button
              class="btn btn-primary"
              :disabled="!hasPhoneMapping"
              @click="startImport"
            >
              Importar {{ validPhoneCount }} contatos <i class="ti ti-arrow-right ms-1"></i>
            </button>
          </template>

          <template v-if="step === 3">
            <button
              v-if="importStatus?.status === 'completed' || importStatus?.status === 'failed'"
              class="btn btn-primary"
              data-bs-dismiss="modal"
              @click="onConcluir"
            >
              Concluir
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

interface ContactList {
  id: number
  name: string
  contact_count: number
}

interface ImportStatusResponse {
  id: number
  status: 'pending' | 'processing' | 'completed' | 'failed'
  total_rows: number
  processed_rows: number
  error_message: string | null
}

const props = defineProps<{ contactListId?: number | null }>()
const emit = defineEmits<{ submitted: [] }>()

const { get, upload } = useApi()
const toast = useToast()

// Refs
const modalEl = ref<HTMLElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const step = ref(1)
const isDragging = ref(false)

// Step 1 state
const file = ref<File | null>(null)
const contactLists = ref<ContactList[]>([])
const selectedListId = ref<number | null>(props.contactListId ?? null)

// Step 2 state
const headers = ref<string[]>([])
const previewRows = ref<string[][]>([])
const totalRows = ref(0)
const delimiter = ref(';')
const columnMapping = ref<string[]>([])

// Step 3 state
const importStatus = ref<ImportStatusResponse | null>(null)
let pollingTimer: ReturnType<typeof setTimeout> | null = null

// Computed
const hasPhoneMapping = computed(() => columnMapping.value.includes('phone'))

const validPhoneCount = computed(() => {
  const phoneIdx = columnMapping.value.indexOf('phone')
  if (phoneIdx === -1) return 0
  // We need to re-read all data rows for counting, but we only have preview.
  // Use totalRows as an estimate; the preview rows give us a sample validation.
  // For accurate count, read all rows.
  return validPhonesTotal.value
})

const validPhonesTotal = ref(0)

const progressPercent = computed(() => {
  if (!importStatus.value) return 0
  const total = importStatus.value.total_rows || totalRows.value
  if (total === 0) return 0
  return Math.min(100, Math.round((importStatus.value.processed_rows / total) * 100))
})

// Methods
function formatSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function downloadTemplate() {
  const csv = 'phone;name;email\n+5511999999999;Joao Silva;joao@email.com\n+5521988888888;Maria Santos;maria@email.com'
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'template_contatos.csv'
  a.click()
  URL.revokeObjectURL(url)
}

function validateFile(f: File): boolean {
  if (f.size > 10 * 1024 * 1024) {
    toast.error('Arquivo muito grande. Tamanho maximo: 10MB')
    return false
  }
  return true
}

function onFileSelect(e: Event) {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0]
  if (f && validateFile(f)) {
    file.value = f
  }
  // Reset input so same file can be re-selected
  input.value = ''
}

function onDrop(e: DragEvent) {
  isDragging.value = false
  const f = e.dataTransfer?.files?.[0]
  if (f && validateFile(f)) {
    file.value = f
  }
}

function autoDetectMapping() {
  columnMapping.value = headers.value.map(h => {
    const lower = h.toLowerCase()
    if (/phone|telefone|celular|whatsapp|fone/.test(lower)) return 'phone'
    if (/name|nome/.test(lower)) return 'name'
    if (/e-?mail/.test(lower)) return 'email'
    return 'ignore'
  })
}

function parseCSV(): Promise<void> {
  return new Promise((resolve) => {
    const reader = new FileReader()
    reader.onload = (e) => {
      const text = e.target?.result as string
      const lines = text.split(/\r?\n/).filter(l => l.trim())

      // Detect delimiter
      const firstLine = lines[0] ?? ''
      delimiter.value = (firstLine.split(';').length >= firstLine.split(',').length) ? ';' : ','

      // Parse header + 5 rows
      headers.value = firstLine.split(delimiter.value).map(h => h.trim().replace(/^"/, '').replace(/"$/, ''))
      previewRows.value = lines.slice(1, 6).map(l =>
        l.split(delimiter.value).map(c => c.trim().replace(/^"/, '').replace(/"$/, ''))
      )
      totalRows.value = lines.length - 1

      // Auto-detect mapping
      autoDetectMapping()

      // Count valid phones from all data rows
      const phoneIdx = columnMapping.value.indexOf('phone')
      if (phoneIdx !== -1) {
        const phoneRegex = /^\+?\d{10,15}$/
        let count = 0
        for (let i = 1; i < lines.length; i++) {
          const cols = lines[i].split(delimiter.value).map(c => c.trim().replace(/^"/, '').replace(/"$/, ''))
          if (cols[phoneIdx] && phoneRegex.test(cols[phoneIdx])) {
            count++
          }
        }
        validPhonesTotal.value = count
      } else {
        validPhonesTotal.value = 0
      }

      resolve()
    }
    reader.readAsText(file.value!)
  })
}

// Recount valid phones when mapping changes
function recountPhones() {
  const phoneIdx = columnMapping.value.indexOf('phone')
  if (phoneIdx === -1 || !file.value) {
    validPhonesTotal.value = 0
    return
  }
  // Re-read file to count
  const reader = new FileReader()
  reader.onload = (e) => {
    const text = e.target?.result as string
    const lines = text.split(/\r?\n/).filter(l => l.trim())
    const phoneRegex = /^\+?\d{10,15}$/
    let count = 0
    for (let i = 1; i < lines.length; i++) {
      const cols = lines[i].split(delimiter.value).map(c => c.trim().replace(/^"/, '').replace(/"$/, ''))
      if (cols[phoneIdx] && phoneRegex.test(cols[phoneIdx])) {
        count++
      }
    }
    validPhonesTotal.value = count
  }
  reader.readAsText(file.value)
}

async function goToStep2() {
  await parseCSV()
  step.value = 2
}

function goBackToStep1() {
  step.value = 1
  headers.value = []
  previewRows.value = []
  columnMapping.value = []
}

async function startImport() {
  step.value = 3
  importStatus.value = null

  const form = new FormData()
  form.append('file', file.value!)
  form.append('contact_list_id', String(selectedListId.value))

  // Build mapping from column assignments
  const mappingObj: Record<string, string> = {}
  columnMapping.value.forEach((role, idx) => {
    if (role !== 'ignore') {
      mappingObj[role] = headers.value[idx]
    }
  })
  form.append('mapping', JSON.stringify(mappingObj))

  try {
    const res = await upload<{ import_id: number }>('/contacts/import', form)
    const importId = (res as any).import_id ?? (res as any).id
    startPolling(importId)
  } catch (e: any) {
    importStatus.value = {
      id: 0,
      status: 'failed',
      total_rows: totalRows.value,
      processed_rows: 0,
      error_message: e?.response?.data?.message ?? 'Erro ao enviar arquivo CSV',
    }
  }
}

async function startPolling(importId: number) {
  let delay = 2000
  const poll = async () => {
    try {
      const res = await get<ImportStatusResponse>(`/contacts/import/${importId}`)
      importStatus.value = res
      if (res.status === 'completed' || res.status === 'failed') return
      delay = Math.min(delay * 2, 10000)
      pollingTimer = setTimeout(poll, delay)
    } catch {
      pollingTimer = setTimeout(poll, delay)
    }
  }
  pollingTimer = setTimeout(poll, delay)
}

function onConcluir() {
  emit('submitted')
  resetState()
}

function resetState() {
  if (pollingTimer) {
    clearTimeout(pollingTimer)
    pollingTimer = null
  }
  step.value = 1
  file.value = null
  isDragging.value = false
  selectedListId.value = props.contactListId ?? null
  headers.value = []
  previewRows.value = []
  totalRows.value = 0
  columnMapping.value = []
  importStatus.value = null
  validPhonesTotal.value = 0
}

async function fetchContactLists() {
  try {
    const res = await get<any>('/contact-lists')
    contactLists.value = Array.isArray(res) ? res : (res?.data ?? [])
  } catch {
    contactLists.value = []
  }
}

onMounted(() => {
  fetchContactLists()

  // Listen for Bootstrap modal hidden event to reset state
  modalEl.value?.addEventListener('hidden.bs.modal', resetState)
})

onBeforeUnmount(() => {
  modalEl.value?.removeEventListener('hidden.bs.modal', resetState)
  if (pollingTimer) {
    clearTimeout(pollingTimer)
    pollingTimer = null
  }
})
</script>
