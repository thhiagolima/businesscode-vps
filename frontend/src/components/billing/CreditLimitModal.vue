<template>
  <div
    class="modal modal-blur fade show d-block"
    style="background:rgba(0,0,0,0.5)"
    tabindex="-1"
    role="dialog"
    @click.self="$emit('close')"
  >
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content" style="border-radius:14px">
        <div class="modal-header">
          <h5 class="modal-title" style="font-weight:700">
            Ajustar limite de crédito
          </h5>
          <button type="button" class="btn-close" aria-label="Fechar" @click="$emit('close')"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info mb-3" role="alert">
            <i class="ti ti-info-circle me-1"></i>
            O limite define quanto este tenant pode "ficar negativo" antes de ser suspenso.
            Limite atual: <strong>R$ {{ formatCents(currentCents) }}</strong>.
          </div>

          <div class="mb-3">
            <label class="form-label required">Novo limite (R$)</label>
            <input
              type="number"
              class="form-control"
              :class="{ 'is-invalid': errors.limit }"
              v-model.number="limitBrl"
              step="0.01"
              min="0"
              style="border-radius:10px"
            />
            <div v-if="errors.limit" class="invalid-feedback">{{ errors.limit }}</div>
            <div v-else class="form-hint" style="font-size:0.75rem">
              Use 0 para desativar a linha de crédito (prepaid only).
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label required">Razão</label>
            <textarea
              class="form-control"
              :class="{ 'is-invalid': errors.reason }"
              v-model="reason"
              rows="3"
              placeholder="Mínimo 5 caracteres. Será registrado no audit log."
              style="border-radius:10px"
            ></textarea>
            <div v-if="errors.reason" class="invalid-feedback">{{ errors.reason }}</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link" @click="$emit('close')">Cancelar</button>
          <button
            type="button"
            class="btn btn-primary"
            :disabled="saving"
            @click="save"
          >
            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
            Salvar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useBillingStore } from '@/stores/billing'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  tenantId: number
  currentCents: number
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'saved'): void
}>()

const limitBrl = ref<number>(Number(((props.currentCents ?? 0) / 100).toFixed(2)))
const reason = ref('')
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const store = useBillingStore()
const toast = useToast()

function formatCents(c: number): string {
  return (Number(c || 0) / 100).toFixed(2).replace('.', ',')
}

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (isNaN(limitBrl.value) || limitBrl.value < 0) {
    errs.limit = 'Informe um valor válido (>= 0)'
  }
  if (!reason.value || reason.value.trim().length < 5) {
    errs.reason = 'A razão deve ter pelo menos 5 caracteres'
  }
  errors.value = errs
  return Object.keys(errs).length === 0
}

async function save() {
  if (!validate()) return
  saving.value = true
  try {
    await store.setCreditLimit(
      props.tenantId,
      Math.round(limitBrl.value * 100),
      reason.value.trim()
    )
    toast.success('Limite de crédito atualizado')
    emit('saved')
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao salvar limite')
  } finally {
    saving.value = false
  }
}
</script>
