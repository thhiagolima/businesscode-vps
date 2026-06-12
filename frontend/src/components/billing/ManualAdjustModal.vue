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
            Ajuste manual de saldo
          </h5>
          <button type="button" class="btn-close" aria-label="Fechar" @click="$emit('close')"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning mb-3" role="alert">
            <i class="ti ti-alert-triangle me-1"></i>
            Valores positivos <strong>creditam</strong> a conta, negativos <strong>debitam</strong>.
            Toda alteração fica registrada em <code>balance_transactions</code>.
          </div>

          <div class="mb-3">
            <label class="form-label required">Valor (R$)</label>
            <div class="input-group">
              <span class="input-group-text">R$</span>
              <input
                type="number"
                class="form-control"
                :class="{ 'is-invalid': errors.amount }"
                v-model.number="amountBrl"
                step="0.01"
                placeholder="Ex: 50,00 ou -25,00"
                style="border-radius:0 10px 10px 0"
              />
            </div>
            <div v-if="errors.amount" class="invalid-feedback d-block">{{ errors.amount }}</div>
            <div v-else class="form-hint" style="font-size:0.75rem">
              Use valores negativos para debitar (ex: <code>-25.00</code>).
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label required">Razão</label>
            <textarea
              class="form-control"
              :class="{ 'is-invalid': errors.reason }"
              v-model="reason"
              rows="3"
              placeholder="Mínimo 5 caracteres. Ex: 'Bônus retenção', 'Reembolso erro de cobrança'."
              style="border-radius:10px"
            ></textarea>
            <div v-if="errors.reason" class="invalid-feedback">{{ errors.reason }}</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link" @click="$emit('close')">Cancelar</button>
          <button
            type="button"
            :class="['btn', amountBrl >= 0 ? 'btn-success' : 'btn-danger']"
            :disabled="saving"
            @click="save"
          >
            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
            {{ amountBrl >= 0 ? 'Creditar' : 'Debitar' }}
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
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'saved'): void
}>()

const amountBrl = ref<number>(0)
const reason = ref('')
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const store = useBillingStore()
const toast = useToast()

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (isNaN(amountBrl.value) || amountBrl.value === 0) {
    errs.amount = 'Informe um valor diferente de zero'
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
    await store.adjustBalance(
      props.tenantId,
      Math.round(amountBrl.value * 100),
      reason.value.trim()
    )
    toast.success('Ajuste aplicado')
    emit('saved')
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao ajustar saldo')
  } finally {
    saving.value = false
  }
}
</script>
