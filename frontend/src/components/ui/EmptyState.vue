<template>
  <div class="empty py-5">
    <div class="empty-icon">
      <i :class="['ti', icon]" style="font-size:2.5rem"></i>
    </div>
    <p class="empty-title">{{ title }}</p>
    <p v-if="description" class="empty-subtitle text-muted">{{ description }}</p>
    <div v-if="$slots.default" class="empty-action">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  icon?: string
  title?: string
  description?: string
}>(), {
  icon: 'ti-database',
  title: 'Nenhum item encontrado',
  description: '',
})
</script>
