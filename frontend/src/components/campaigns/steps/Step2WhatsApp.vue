<template>
  <div>
    <h3 class="mb-3">Selecionar Template WhatsApp</h3>

    <div v-if="loading" class="text-center py-4">
      <div class="spinner-border text-primary"></div>
      <p class="text-muted mt-2">Carregando templates...</p>
    </div>

    <div v-else-if="!templates.length" class="empty py-4">
      <div class="empty-icon"><i class="ti ti-brand-whatsapp"></i></div>
      <p class="empty-title">Nenhum template aprovado</p>
      <p class="empty-subtitle text-muted">Configure o WhatsApp no painel Admin e sincronize os templates.</p>
    </div>

    <template v-else>
      <div class="mb-3">
        <label class="form-label">Template *</label>
        <select class="form-select" v-model="selectedName" @change="onSelectTemplate">
          <option value="">Selecione um template...</option>
          <option v-for="t in templates" :key="t.name" :value="t.name">
            {{ t.name }} ({{ t.language }}) — {{ t.category }}
          </option>
        </select>
      </div>

      <div v-if="selected" class="card">
        <div class="card-header">
          <div class="card-title">Preview: {{ selected.name }}</div>
          <span class="badge bg-green ms-auto">{{ selected.status }}</span>
        </div>
        <div class="card-body">
          <div v-for="(comp, ci) in selected.components" :key="ci" class="mb-3">
            <div class="fw-bold text-uppercase small text-muted mb-1">{{ comp.type }}</div>

            <div v-if="comp.type === 'BODY'" class="bg-light rounded p-3" style="white-space:pre-wrap" v-html="previewBodyHtml(comp)">
            </div>
            <div v-else-if="comp.type === 'HEADER'" class="fw-bold">
              {{ comp.text ?? comp.format }}
            </div>
            <div v-else-if="comp.type === 'FOOTER'" class="text-muted small">
              {{ comp.text }}
            </div>
            <div v-else-if="comp.type === 'BUTTONS'">
              <div v-for="(btn, bi) in comp.buttons" :key="bi" class="btn btn-sm btn-outline-primary me-2 mt-1">
                {{ btn.text }}
              </div>
            </div>
          </div>

          <div v-if="bodyParams.length" class="mt-3 pt-3 border-top">
            <h4 class="mb-2">Variáveis do template</h4>
            <div v-for="(param, pi) in bodyParams" :key="pi" class="row mb-2 align-items-center">
              <div class="col-auto">
                <span class="badge bg-azure">{{ paramLabel(pi) }}</span>
              </div>
              <div class="col">
                <select class="form-select form-select-sm" v-model="paramValues[pi]" @change="emitSettings">
                  <option value="">Selecione...</option>
                  <option value="{nome}">Nome do contato</option>
                  <option value="{telefone}">Telefone</option>
                  <option value="{email}">Email</option>
                  <option value="__custom__">Texto fixo...</option>
                </select>
                <input v-if="paramValues[pi] === '__custom__'" class="form-control form-control-sm mt-1"
                       v-model="customValues[pi]" placeholder="Digite o valor fixo" @input="emitSettings" />
              </div>
            </div>
            <div v-if="bodyParams.length && !hasAllParams" class="alert alert-warning mt-2" style="font-size:0.85rem">
              <i class="ti ti-alert-triangle me-1"></i> Mapeie todas as variáveis para continuar.
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DOMPurify from 'dompurify'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ modelValue: Record<string, any> | null }>()
const emit = defineEmits<{
  'update:modelValue': [value: Record<string, any> | null]
  'update:valid': [valid: boolean]
}>()

const { get } = useApi()
const toast = useToast()

const loading = ref(false)
const templates = ref<any[]>([])
const selectedName = ref('')
const selected = ref<any>(null)
const paramValues = ref<string[]>([])
const customValues = ref<string[]>([])

const bodyParams = computed(() => {
  if (!selected.value) return []
  const body = selected.value.components?.find((c: any) => c.type === 'BODY')
  if (!body?.text) return []
  const matches = body.text.match(/\{\{\d+\}\}/g)
  return matches ?? []
})

const hasAllParams = computed(() => {
  if (!bodyParams.value.length) return true
  return paramValues.value.every((v, i) => {
    if (v === '__custom__') return !!(customValues.value[i] || '')
    return v !== ''
  })
})

function paramLabel(index: number): string {
  return `{{${index + 1}}}`
}

function previewBody(comp: any): string {
  let text = comp.text ?? ''
  bodyParams.value.forEach((_: any, i: number) => {
    const val = paramValues.value[i] === '__custom__' ? (customValues.value[i] || `{{${i+1}}}`) : (paramValues.value[i] || `{{${i+1}}}`)
    text = text.replace(`{{${i+1}}}`, val)
  })
  return text
}

function previewBodyHtml(comp: any): string {
  let text = previewBody(comp)
  // Escape HTML first
  text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  // Highlight known variables
  text = text
    .replace(/\{nome\}/g, '<span class="badge bg-blue-lt">João Silva</span>')
    .replace(/\{telefone\}/g, '<span class="badge bg-green-lt">+5511999999999</span>')
    .replace(/\{email\}/g, '<span class="badge bg-purple-lt">joao@email.com</span>')
    .replace(/\{\{(\d+)\}\}/g, '<span class="badge bg-yellow-lt">Variável $1</span>')
  return DOMPurify.sanitize(text, { ALLOWED_TAGS: ['span'], ALLOWED_ATTR: ['class'] })
}

function onSelectTemplate() {
  selected.value = templates.value.find(t => t.name === selectedName.value) ?? null
  paramValues.value = bodyParams.value.map(() => '')
  customValues.value = bodyParams.value.map(() => '')
  emitSettings()
}

function emitSettings() {
  if (!selected.value) {
    emit('update:modelValue', null)
    emit('update:valid', false)
    return
  }

  const parameters = paramValues.value.map((v, i) => ({
    type: 'text' as const,
    text: v === '__custom__' ? (customValues.value[i] || '') : (v || ''),
  }))

  const hasAllParams = bodyParams.value.length === 0 || parameters.every(p => p.text !== '')

  const settings = {
    template_name: selected.value.name,
    template_language: selected.value.language,
    components: parameters.length ? [{
      type: 'body',
      parameters,
    }] : [],
  }

  emit('update:modelValue', settings)
  emit('update:valid', hasAllParams)
}

onMounted(async () => {
  loading.value = true
  try {
    templates.value = await get<any[]>('/whatsapp/templates') ?? []
  } catch (e: any) {
    toast.error('Erro ao carregar templates WhatsApp')
  } finally {
    loading.value = false
  }

  if (props.modelValue?.template_name) {
    selectedName.value = props.modelValue.template_name
    onSelectTemplate()
  }
})
</script>
