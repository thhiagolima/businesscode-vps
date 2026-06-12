<template>
  <div>
    <ContactsTable
      :items="items"
      :loading="loading"
      :allow-delete="true"
      empty-hint="Sem contatos para este tenant."
      @action="onAction"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import ContactsTable from '@/components/shared/tables/ContactsTable.vue'

const props = defineProps<{ tenantId: number }>()
const { get, del } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/contacts`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

async function onAction({ type, item }: { type: string; item: any }) {
  if (type === 'delete') {
    if (!confirm(`Excluir contato '${item.name}'?`)) return
    try {
      await del(`/admin/tenants/${props.tenantId}/contacts/${item.id}`)
      toast.success('Removido')
      await load()
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Erro')
    }
  }
}

onMounted(load)
</script>
