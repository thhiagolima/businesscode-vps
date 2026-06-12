<template>
  <div class="bc-filter-bar">
    <div class="bc-filter-search" v-if="searchable">
      <i class="ti ti-search"></i>
      <input type="text" :value="search" @input="$emit('update:search', ($event.target as HTMLInputElement).value)" :placeholder="searchPlaceholder" />
    </div>
    <slot />
    <div v-if="$slots.actions" class="bc-filter-actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  searchable?: boolean
  search?: string
  searchPlaceholder?: string
}>(), {
  searchable: true,
  search: '',
  searchPlaceholder: 'Buscar...',
})

defineEmits<{
  'update:search': [value: string]
}>()
</script>

<style scoped>
.bc-filter-bar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  background: var(--bc-gray-soft, #f0f2f7);
  border-radius: 10px;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}
.bc-filter-search {
  display: flex;
  align-items: center;
  background: var(--bc-white, #fff);
  border: 1px solid var(--bc-gray, #e4e8ef);
  border-radius: 8px;
  padding: 0 0.75rem;
  flex: 1 1 220px;
  min-width: 200px;
  transition: all 0.2s;
}
.bc-filter-search:focus-within {
  border-color: var(--bc-primary);
  box-shadow: 0 0 0 3px rgba(0, 100, 255, 0.06);
}
.bc-filter-search i {
  color: var(--bc-text-muted, #6c7293);
  font-size: 1rem;
  margin-right: 0.5rem;
  flex-shrink: 0;
}
.bc-filter-search:focus-within i {
  color: var(--bc-primary);
}
.bc-filter-search input {
  border: none;
  outline: none;
  background: transparent;
  padding: 0.5rem 0;
  font-size: 0.85rem;
  width: 100%;
  font-family: inherit;
  color: var(--bc-text, #1a1d2e);
}
.bc-filter-search input::placeholder {
  color: #c0c5d0;
}
.bc-filter-bar :deep(.form-select) {
  border-radius: 8px;
  font-size: 0.82rem;
  padding: 0.45rem 2rem 0.45rem 0.75rem;
  border-color: var(--bc-gray);
  background-color: var(--bc-white, #fff);
  min-width: 140px;
  /* Bootstrap define form-select com width:100%, que dentro de flex+wrap empurra
     cada select para uma linha inteira. Limitamos para o select crescer só até
     o conteúdo natural — assim ficam lado a lado com o search. */
  width: auto;
  flex: 0 1 auto;
}
.bc-filter-actions {
  margin-left: auto;
}
</style>
