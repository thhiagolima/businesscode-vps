<template>
  <div class="modal modal-blur fade show d-block" style="background:rgba(0,0,0,0.4)" v-if="open">
    <div class="modal-dialog modal-md">
      <div class="modal-content" style="border-radius:14px">
        <div class="modal-header">
          <h5 class="modal-title"><i class="ti ti-key me-2"></i>Senha temporária gerada</h5>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning" style="border-radius:12px">
            <i class="ti ti-alert-triangle me-1"></i>
            <strong>Esta senha será exibida apenas uma vez.</strong> Anote ou copie agora.
          </div>
          <div class="d-flex align-items-center gap-2 my-3">
            <input :value="password" readonly class="form-control" style="font-family:'JetBrains Mono',monospace" />
            <button class="btn btn-primary" @click="copy">
              <i class="ti ti-copy me-1"></i>Copiar
            </button>
          </div>
          <p style="font-size:0.85rem;color:var(--bc-text-muted)">
            O usuário será obrigado a trocar a senha no próximo login. Todos os tokens ativos foram revogados.
          </p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" :disabled="!copied" @click="$emit('close')">
            {{ copied ? 'Entendi' : 'Copie a senha primeiro' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
const props = defineProps<{ open: boolean; password: string }>()
defineEmits<{ (e: 'close'): void }>()
const copied = ref(false)
watch(() => props.open, v => { if (v) copied.value = false })
function copy() {
  navigator.clipboard.writeText(props.password)
  copied.value = true
}
</script>
