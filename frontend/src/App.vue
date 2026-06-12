<template>
  <AppLayout v-if="showLayout" />
  <RouterView v-else />
</template>

<script setup lang="ts">
import { onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import AppLayout from '@/components/layout/AppLayout.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const showLayout = computed(() => {
  if (route.meta?.public) return false
  if (route.path.startsWith('/plans')) return false
  return true
})

onMounted(async () => {
  if (auth.isAuthenticated) {
    await auth.refreshUser()
  }
})
</script>
