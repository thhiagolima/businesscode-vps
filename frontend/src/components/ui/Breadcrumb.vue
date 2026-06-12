<template>
  <div class="page-header d-print-none">
    <div class="container-xl">
      <div class="row g-2 align-items-center">
        <div class="col">
          <div class="page-pretitle">
            <ol class="breadcrumb breadcrumb-arrows">
              <li v-for="(item, i) in items" :key="i" class="breadcrumb-item" :class="{ active: i === items.length - 1 }">
                <a v-if="i < items.length - 1" href="#" @click.prevent="$router.push(item.path!)">{{ item.label }}</a>
                <span v-else>{{ item.label }}</span>
              </li>
            </ol>
          </div>
          <h2 class="page-title">{{ title }}</h2>
        </div>
        <div v-if="$slots.actions" class="col-auto ms-auto d-print-none">
          <slot name="actions" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  items: Array<{ label: string; path?: string }>
}>()
</script>
