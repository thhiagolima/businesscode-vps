<template>
  <div class="modal modal-blur fade" :class="{ show: visible }" :style="{ display: visible ? 'block' : 'none' }" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body text-center py-4">
          <i :class="['ti', iconClass, 'mb-2']" :style="{ color: iconColor, fontSize: '3rem' }"></i>
          <h3>{{ title }}</h3>
          <div class="text-muted">{{ message }}</div>
        </div>
        <div class="modal-footer">
          <div class="w-100">
            <div class="row">
              <div class="col">
                <button class="btn w-100" @click="$emit('cancel')">Cancelar</button>
              </div>
              <div class="col">
                <button :class="['btn', 'w-100', confirmClass]" @click="$emit('confirm')" :disabled="loading">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                  {{ confirmText }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div v-if="visible" class="modal-backdrop fade show"></div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  visible: boolean
  title?: string
  message?: string
  confirmText?: string
  confirmClass?: string
  iconClass?: string
  iconColor?: string
  loading?: boolean
}>(), {
  title: 'Confirmar ação',
  message: 'Tem certeza que deseja continuar?',
  confirmText: 'Confirmar',
  confirmClass: 'btn-danger',
  iconClass: 'ti-alert-triangle',
  iconColor: '#d63939',
  loading: false,
})

defineEmits<{
  confirm: []
  cancel: []
}>()
</script>
