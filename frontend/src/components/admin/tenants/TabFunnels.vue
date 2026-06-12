<template>
  <div>
    <FunnelsTable
      :items="items"
      :loading="loading"
      :allow-delete="true"
      empty-hint="Sem funis para este tenant."
      @action="onAction"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import FunnelsTable from '@/components/shared/tables/FunnelsTable.vue'

const props = defineProps<{ tenantId: number }>()
const { get, patch, del } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/funnels`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

async function onAction({ type, item }: { type: string; item: any }) {
  try {
    if (type === 'view') {
      window.open(`/funnels/${item.id}/edit?admin_tenant=${props.tenantId}`, '_blank')
    } else if (type === 'toggle') {
      const newStatus = item.status === 'active' ? 'paused' : 'active'
      await patch(`/admin/tenants/${props.tenantId}/funnels/${item.id}`, { status: newStatus })
      toast.success(`Funil ${newStatus === 'active' ? 'ativado' : 'pausado'}`)
      await load()
    } else if (type === 'delete') {
      if (!confirm(`Excluir funil '${item.name}'?`)) return
      await del(`/admin/tenants/${props.tenantId}/funnels/${item.id}`)
      toast.success('Funil excluído')
      await load()
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

onMounted(load)
</script>
