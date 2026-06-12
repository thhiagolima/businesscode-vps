<template>
  <div class="bc-page-header">
    <div class="container-xl">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
          <div v-if="breadcrumbs.length" class="bc-breadcrumbs">
            <template v-for="(item, i) in breadcrumbs" :key="i">
              <a v-if="item.path" href="#" @click.prevent="$router.push(item.path)" class="bc-breadcrumb-link">{{ item.label }}</a>
              <span v-else class="bc-breadcrumb-current">{{ item.label }}</span>
              <i v-if="i < breadcrumbs.length - 1" class="ti ti-chevron-right bc-breadcrumb-sep"></i>
            </template>
          </div>
          <h1 class="bc-page-title">{{ title }}</h1>
          <p v-if="subtitle" class="bc-page-subtitle">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="d-flex align-items-center gap-2">
          <slot name="actions" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  title: string
  subtitle?: string
  breadcrumbs?: Array<{ label: string; path?: string }>
}>(), {
  breadcrumbs: () => [],
})
</script>

<style scoped>
.bc-page-header {
  background: var(--bc-white, #fff);
  border-bottom: 1px solid var(--bc-gray, #e4e8ef);
  padding: 1.25rem 0;
}
.bc-breadcrumbs {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-bottom: 0.25rem;
}
.bc-breadcrumb-link {
  font-size: 0.75rem;
  color: var(--bc-text-muted, #6c7293);
  text-decoration: none;
  font-weight: 500;
}
.bc-breadcrumb-link:hover { color: var(--bc-primary); }
.bc-breadcrumb-sep {
  font-size: 0.65rem;
  color: var(--bc-gray, #e4e8ef);
}
.bc-breadcrumb-current {
  font-size: 0.75rem;
  color: var(--bc-text-muted);
}
.bc-page-title {
  font-size: 1.4rem;
  font-weight: 700;
  letter-spacing: -0.03em;
  color: var(--bc-text, #1a1d2e);
  margin: 0;
  line-height: 1.3;
}
.bc-page-subtitle {
  font-size: 0.85rem;
  color: var(--bc-text-muted);
  margin: 0.25rem 0 0;
}
</style>
