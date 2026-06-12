<template>
  <div>
    <ConversationsTable
      :items="items"
      :loading="loading"
      empty-hint="Sem conversas para este tenant."
      @action="onAction"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import ConversationsTable from '@/components/shared/tables/ConversationsTable.vue'

const props = defineProps<{ tenantId: number }>()
const { get, patch } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/conversations`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

async function onAction({ type, item }: { type: string; item: any }) {
  if (type === 'close') {
    try {
      await patch(`/admin/tenants/${props.tenantId}/conversations/${item.id}`, { status: 'closed' })
      toast.success('Conversa fechada')
      await load()
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Erro')
    }
  } else if (type === 'view') {
    window.open(`/conversations?id=${item.id}`, '_blank')
  }
}

onMounted(load)
</script>
