<template>
  <div style="padding:8px 16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <span style="font-size:12px;font-weight:600;color:#666">Triggers:</span>
    <div v-for="(t, i) in localTriggers" :key="i" style="display:flex;gap:4px;align-items:center">
      <select class="form-select form-select-sm" v-model="t.type" @change="emitUpdate" style="width:110px;font-size:11px">
        <option value="keyword">Palavra-chave</option>
        <option value="default">Catch-all</option>
      </select>
      <input v-if="t.type === 'keyword'" class="form-control form-control-sm" v-model="t.pattern" @input="emitUpdate" placeholder="oi|olá|hello" style="width:160px;font-size:11px">
      <button class="btn btn-sm btn-ghost-danger" @click="removeTrigger(i)" style="padding:2px 6px;font-size:11px">✕</button>
    </div>
    <button class="btn btn-sm btn-ghost-primary" @click="addTrigger" style="font-size:11px">+ Trigger</button>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'

const props = defineProps<{ triggers: any[] }>()
const emit = defineEmits<{ 'update:triggers': [triggers: any[]] }>()

const localTriggers = ref<any[]>([])

watch(() => props.triggers, (t) => {
  localTriggers.value = (t ?? []).map(x => ({ ...x }))
}, { immediate: true })

function addTrigger() {
  localTriggers.value.push({ type: 'keyword', pattern: '' })
  emitUpdate()
}
function removeTrigger(i: number) {
  localTriggers.value.splice(i, 1)
  emitUpdate()
}
function emitUpdate() {
  emit('update:triggers', localTriggers.value.map(t => ({ ...t })))
}
</script>
