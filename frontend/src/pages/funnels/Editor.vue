<template>
  <div class="d-lg-none text-center py-5">
    <div class="mb-3">
      <i class="ti ti-device-desktop" style="font-size:3rem;color:var(--bc-text-muted)"></i>
    </div>
    <h3>Editor disponível em desktop</h3>
    <p class="text-muted">O editor visual de funis requer uma tela maior para funcionar corretamente.<br>Acesse em um computador para editar seus funis.</p>
    <button class="btn btn-primary" @click="$router.push('/funnels')">
      <i class="ti ti-arrow-left me-1"></i> Voltar para funis
    </button>
  </div>

  <div class="d-none d-lg-flex" style="flex-direction:column;height:calc(100vh - 64px);overflow:hidden">
    <div v-if="$route.query.admin_tenant" class="alert alert-warning d-flex align-items-center mb-3" style="border-radius:12px">
      <i class="ti ti-shield-lock me-2"></i>
      <div>
        <strong>Modo administrador.</strong>
        Editando funil do tenant #{{ $route.query.admin_tenant }} como superadmin. Toda alteração será auditada.
      </div>
    </div>
    <!-- Header -->
    <div style="padding:8px 16px;border-bottom:1px solid var(--bc-outline);display:flex;align-items:center;gap:12px;background:var(--bc-dark-surface);flex-shrink:0">
      <button class="btn btn-sm btn-ghost-secondary" @click="$router.push('/funnels')">← Voltar</button>
      <input class="form-control form-control-sm" v-model="funnelName" style="max-width:250px" placeholder="Nome do funil">
      <span v-if="isDirty" class="unsaved-dot" title="Alterações não salvas"></span>
      <div class="ms-auto d-flex gap-2">
        <button class="btn btn-sm btn-primary" @click="save" :disabled="saving">
          <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
          Salvar
        </button>
        <button v-if="funnel?.status !== 'active'" class="btn btn-sm btn-success" @click="activate" :disabled="saving">Ativar</button>
        <button v-if="funnel?.status === 'active'" class="btn btn-sm btn-warning" @click="pause" :disabled="saving">Pausar</button>
      </div>
    </div>

    <!-- Main area -->
    <div style="display:flex;flex:1;overflow:hidden">
      <!-- Toolbar -->
      <div style="width:120px;flex-shrink:0;border-right:1px solid var(--bc-outline);background:var(--bc-dark-surface);overflow-y:auto">
        <FunnelToolbar />
      </div>

      <!-- Canvas -->
      <div style="flex:1;position:relative"
        @drop="onDrop"
        @dragover.prevent
        @dragenter.prevent>
        <VueFlow
          v-model:nodes="nodes"
          v-model:edges="edges"
          :node-types="nodeTypes"
          :default-edge-options="{ type: 'smoothstep', animated: true }"
          fit-view-on-init
          @node-click="onNodeClick"
          @pane-click="selectedNode = null"
        >
          <Background pattern-color="#2a2f55" :gap="20" />
          <Controls position="bottom-left" />
          <MiniMap
            position="bottom-right"
            pannable
            zoomable
            :width="160"
            :height="100"
            mask-color="rgba(8,12,37,0.85)"
            node-color="#0064ff"
            node-stroke-color="#3b82f6"
            :node-border-radius="4"
          />
        </VueFlow>
      </div>

      <!-- Properties Panel -->
      <div style="width:300px;flex-shrink:0;border-left:1px solid var(--bc-outline);background:var(--bc-dark-surface)">
        <NodePropertiesPanel
          :node="selectedNodeData"
          @update:config="onConfigUpdate"
          @update:label="onLabelUpdate"
        />
      </div>
    </div>

    <!-- Triggers footer -->
    <div style="border-top:1px solid var(--bc-outline);background:var(--bc-dark-surface);flex-shrink:0">
      <TriggerConfig :triggers="triggers" @update:triggers="triggers = $event" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch, markRaw } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { VueFlow, useVueFlow } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { MiniMap } from '@vue-flow/minimap'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'

import FunnelToolbar from '@/components/funnels/FunnelToolbar.vue'
import NodePropertiesPanel from '@/components/funnels/NodePropertiesPanel.vue'
import TriggerConfig from '@/components/funnels/TriggerConfig.vue'

import FunnelNodeStart from '@/components/funnels/FunnelNodeStart.vue'
import FunnelNodeMessage from '@/components/funnels/FunnelNodeMessage.vue'
import FunnelNodeWait from '@/components/funnels/FunnelNodeWait.vue'
import FunnelNodeCondition from '@/components/funnels/FunnelNodeCondition.vue'
import FunnelNodeTag from '@/components/funnels/FunnelNodeTag.vue'
import FunnelNodeTransferHuman from '@/components/funnels/FunnelNodeTransferHuman.vue'
import FunnelNodeAiReply from '@/components/funnels/FunnelNodeAiReply.vue'

import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, put, post } = useApi()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const nodeTypes = {
  start: markRaw(FunnelNodeStart),
  message: markRaw(FunnelNodeMessage),
  wait: markRaw(FunnelNodeWait),
  condition: markRaw(FunnelNodeCondition),
  tag: markRaw(FunnelNodeTag),
  transfer_human: markRaw(FunnelNodeTransferHuman),
  ai_reply: markRaw(FunnelNodeAiReply),
}

const funnel = ref<any>(null)
const funnelName = ref('')
const nodes = ref<any[]>([])
const edges = ref<any[]>([])
const triggers = ref<any[]>([])
const selectedNode = ref<string | null>(null)
const saving = ref(false)
const isDirty = ref(false)

const { screenToFlowCoordinate } = useVueFlow()

// Track unsaved changes
watch([nodes, edges, triggers, funnelName], () => { isDirty.value = true }, { deep: true })

function warnUnsaved(e: BeforeUnloadEvent) {
  if (isDirty.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}

const selectedNodeData = computed(() => {
  if (!selectedNode.value) return null
  const n = nodes.value.find(n => n.id === selectedNode.value)
  if (!n) return null
  return { id: n.id, type: n.type, data: n.data }
})

function onNodeClick({ node }: any) {
  selectedNode.value = node.id
}

function onDrop(event: DragEvent) {
  const type = event.dataTransfer?.getData('application/funnel-node-type')
  if (!type) return

  const position = screenToFlowCoordinate({ x: event.clientX, y: event.clientY })
  const id = `node_${Date.now()}_${Math.random().toString(36).substr(2, 4)}`

  const defaults: Record<string, any> = {
    message: { message_type: 'text', text: '' },
    wait: { duration: 1, unit: 'hours' },
    condition: { condition_type: 'replied', keyword: '' },
    tag: { action: 'add', tag: '' },
  }

  nodes.value.push({
    id,
    type,
    position,
    data: { label: '', config: defaults[type] ?? {} },
  })

  selectedNode.value = id
}

function onConfigUpdate(nodeId: string, config: any) {
  const node = nodes.value.find(n => n.id === nodeId)
  if (node) node.data = { ...node.data, config }
}

function onLabelUpdate(nodeId: string, label: string) {
  const node = nodes.value.find(n => n.id === nodeId)
  if (node) node.data = { ...node.data, label }
}

async function save() {
  if (nodes.value.length === 0) {
    toast.error('Adicione pelo menos um nó ao funil antes de salvar.')
    return
  }
  saving.value = true
  try {
    const funnelId = funnel.value?.id ?? route.params.id
    if (!funnelId) return

    // Save name + triggers
    await put(`/funnels/${funnelId}`, { name: funnelName.value, triggers: triggers.value })

    // Save canvas
    const payload = {
      nodes: nodes.value.map(n => ({
        node_id: n.id,
        type: n.type,
        label: n.data?.label ?? '',
        config: n.data?.config ?? {},
        position_x: n.position.x,
        position_y: n.position.y,
      })),
      edges: edges.value.map(e => ({
        edge_id: e.id,
        source_node_id: e.source,
        target_node_id: e.target,
        label: e.label ?? (e.sourceHandle === 'yes' ? 'Sim' : e.sourceHandle === 'no' ? 'Não' : null),
      })),
    }

    await put(`/funnels/${funnelId}/canvas`, payload)
    isDirty.value = false
    toast.success('Funil salvo')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
  } finally {
    saving.value = false
  }
}

async function activate() {
  if (nodes.value.length === 0) {
    toast.error('Adicione pelo menos um nó ao funil antes de ativar.')
    return
  }
  if (edges.value.length === 0) {
    toast.error('Conecte os nós do funil antes de ativar.')
    return
  }
  const funnelId = funnel.value?.id ?? route.params.id
  if (!funnelId) return
  await save()
  try {
    await post(`/funnels/${funnelId}/activate`, {})
    funnel.value = { ...funnel.value, status: 'active' }
    toast.success('Funil ativado')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') }
}

async function pause() {
  const funnelId = funnel.value?.id ?? route.params.id
  if (!funnelId) return
  try {
    await post(`/funnels/${funnelId}/pause`, {})
    funnel.value = { ...funnel.value, status: 'paused' }
    toast.success('Funil pausado')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') }
}

async function loadFunnel() {
  const id = route.params.id as string
  if (!id) return

  try {
    const data = await get<any>(`/funnels/${id}`)
    funnel.value = data
    funnelName.value = data?.name ?? ''
    triggers.value = data?.triggers ?? []

    // Hydrate Vue Flow nodes
    nodes.value = (data?.nodes ?? []).map((n: any) => ({
      id: n.node_id,
      type: n.type,
      position: { x: n.position_x, y: n.position_y },
      data: { label: n.label, config: n.config ?? {} },
    }))

    edges.value = (data?.edges ?? []).map((e: any) => ({
      id: e.edge_id,
      source: e.source_node_id,
      target: e.target_node_id,
      label: e.label,
      type: 'smoothstep',
      animated: true,
    }))

    // Reset dirty flag after hydration (nextTick lets the watcher fire first)
    setTimeout(() => { isDirty.value = false }, 0)
  } catch (e: any) {
    toast.error('Erro ao carregar funil')
    router.push('/funnels')
  }
}

onMounted(() => {
  loadFunnel()
  window.addEventListener('beforeunload', warnUnsaved)
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', warnUnsaved)
})
</script>
