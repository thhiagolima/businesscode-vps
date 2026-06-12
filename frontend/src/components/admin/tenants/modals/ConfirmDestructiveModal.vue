<template>
  <div class="modal modal-blur fade show d-block" style="background:rgba(0,0,0,0.4)" v-if="open">
    <div class="modal-dialog modal-sm">
      <div class="modal-content" style="border-radius:14px">
        <div class="modal-header">
          <h5 class="modal-title">Confirmar ação destrutiva</h5>
          <button type="button" class="btn-close" @click="$emit('cancel')"></button>
        </div>
        <div class="modal-body">
          <p>{{ message }}</p>
          <p style="font-size:0.85rem;color:var(--bc-text-muted)">
            Para confirmar, digite <code>{{ keyword }}</code> abaixo:
          </p>
          <input v-model="typed" class="form-control" style="border-radius:10px" />
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost-secondary" @click="$emit('cancel')">Cancelar</button>
          <button class="btn btn-danger" :disabled="typed !== keyword" @click="$emit('confirm')">
            Confirmar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
const props = defineProps<{ open: boolean; keyword: string; message: string }>()
defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>()
const typed = ref('')
watch(() => props.open, (v) => { if (v) typed.value = '' })
</script>
