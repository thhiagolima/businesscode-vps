<template>
  <div>
    <CampaignsTable
      :items="items"
      :loading="loading"
      :allow-delete="true"
      empty-hint="Este tenant ainda não tem campanhas."
      @action="onAction"
    />

    <ConfirmDestructiveModal
      :open="!!pending"
      :keyword="pending?.item?.name ?? ''"
      :message="`Excluir a campanha '${pending?.item?.name}'? Esta ação não pode ser desfeita.`"
      @cancel="pending = null"
      @confirm="confirmDelete"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import CampaignsTable from '@/components/shared/tables/CampaignsTable.vue'
import ConfirmDestructiveModal from './modals/ConfirmDestructiveModal.vue'

const props = defineProps<{ tenantId: number }>()
const { get, patch, del } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const loading = ref(false)
const pending = ref<{ item: any } | null>(null)

async function load() {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/campaigns`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

async function onAction({ type, item }: { type: string; item: any }) {
  if (['pause', 'resume', 'cancel'].includes(type)) {
    const reason = type === 'cancel' ? (prompt('Motivo do cancelamento?') || 'sem motivo') : null
    try {
      await patch(`/admin/tenants/${props.tenantId}/campaigns/${item.id}`, { action: type, reason })
      toast.success(`Campanha ${type} ok`)
      await load()
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Erro')
    }
  } else if (type === 'view') {
    window.open(`/campaigns/${item.id}`, '_blank')
  } else if (type === 'delete') {
    pending.value = { item }
  }
}

async function confirmDelete() {
  if (!pending.value) return
  try {
    await del(`/admin/tenants/${props.tenantId}/campaigns/${pending.value.item.id}`)
    toast.success('Campanha excluída')
    pending.value = null
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

onMounted(load)
</script>
