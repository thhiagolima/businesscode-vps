<template>
  <div>
    <UsersTable :items="items" :loading="loading" :allow-delete="true" @action="onAction" />

    <PasswordResetResultModal
      :open="!!tempPassword"
      :password="tempPassword ?? ''"
      @close="tempPassword = null"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import UsersTable from '@/components/shared/tables/UsersTable.vue'
import PasswordResetResultModal from './modals/PasswordResetResultModal.vue'

const props = defineProps<{ tenantId: number }>()
const { get, post, put, del } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const loading = ref(false)
const tempPassword = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/users`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

async function onAction({ type, item }: { type: string; item: any }) {
  try {
    if (type === 'suspend') {
      await put(`/admin/tenants/${props.tenantId}/users/${item.id}`, { status: 'suspended' })
      toast.success('Suspenso')
    } else if (type === 'activate') {
      await put(`/admin/tenants/${props.tenantId}/users/${item.id}`, { status: 'active' })
      toast.success('Ativado')
    } else if (type === 'reset') {
      const resp = await post<any>(`/admin/tenants/${props.tenantId}/users/${item.id}/reset-password`, {})
      tempPassword.value = resp.data?.temp_password
    } else if (type === 'delete') {
      if (!confirm(`Excluir ${item.email}?`)) return
      await del(`/admin/tenants/${props.tenantId}/users/${item.id}`)
      toast.success('Removido')
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

onMounted(load)
</script>
