<template>
  <div v-if="node" style="padding:16px;overflow-y:auto;height:100%">
    <h4 style="margin:0 0 12px;font-size:14px">Propriedades</h4>

    <!-- Message -->
    <template v-if="node.type === 'message'">
      <div class="mb-3">
        <label class="form-label">Tipo</label>
        <select class="form-select form-select-sm" v-model="config.message_type" @change="emitUpdate">
          <option value="text">Texto</option>
          <option value="template">Template</option>
        </select>
      </div>
      <div v-if="config.message_type === 'text'" class="mb-3">
        <label class="form-label">Mensagem</label>
        <textarea class="form-control form-control-sm" v-model="config.text" @input="emitUpdate" rows="4" placeholder="Olá {nome}! Bem-vindo..."></textarea>
        <div class="form-hint">Variáveis: {nome}, {telefone}, {email}</div>
      </div>
      <div v-else class="mb-3">
        <label class="form-label">Nome do template</label>
        <input class="form-control form-control-sm" v-model="config.template_name" @input="emitUpdate" placeholder="nome_do_template">
      </div>
    </template>

    <!-- Wait -->
    <template v-if="node.type === 'wait'">
      <div class="mb-3">
        <label class="form-label">Duração</label>
        <div class="row g-2">
          <div class="col-6">
            <input type="number" class="form-control form-control-sm" v-model.number="config.duration" @input="emitUpdate" min="1">
          </div>
          <div class="col-6">
            <select class="form-select form-select-sm" v-model="config.unit" @change="emitUpdate">
              <option value="minutes">Minutos</option>
              <option value="hours">Horas</option>
              <option value="days">Dias</option>
            </select>
          </div>
        </div>
      </div>
    </template>

    <!-- Condition -->
    <template v-if="node.type === 'condition'">
      <div class="mb-3">
        <label class="form-label">Tipo de condição</label>
        <select class="form-select form-select-sm" v-model="config.condition_type" @change="emitUpdate">
          <option value="replied">Respondeu?</option>
          <option value="keyword">Palavra-chave</option>
          <option value="has_tag">Tem tag</option>
        </select>
      </div>
      <div v-if="config.condition_type === 'has_tag'" class="mb-3">
        <label class="form-label">Tag</label>
        <input class="form-control form-control-sm" v-model="config.tag" @input="emitUpdate" placeholder="ex: interessado">
      </div>
      <div v-if="config.condition_type === 'keyword'" class="mb-3">
        <label class="form-label">Padrão (regex)</label>
        <input class="form-control form-control-sm" v-model="config.keyword" @input="emitUpdate" placeholder="comprar|preço|valor">
        <div class="form-hint">Use | para múltiplas palavras</div>
      </div>
    </template>

    <!-- Tag -->
    <template v-if="node.type === 'tag'">
      <div class="mb-3">
        <label class="form-label">Ação</label>
        <select class="form-select form-select-sm" v-model="config.action" @change="emitUpdate">
          <option value="add">Adicionar tag</option>
          <option value="remove">Remover tag</option>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Nome da tag</label>
        <input class="form-control form-control-sm" v-model="config.tag" @input="emitUpdate" placeholder="interessado">
      </div>
    </template>

    <!-- Transfer Human -->
    <div v-else-if="node.type === 'transfer_human'">
      <p class="text-muted" style="font-size:12px">Este nó transfere a conversa para um atendente humano e encerra o funil.</p>
    </div>

    <!-- AI Reply -->
    <div v-else-if="node.type === 'ai_reply'">
      <p class="text-muted" style="font-size:12px">A IA gera uma resposta baseada na persona configurada e envia automaticamente. Requer persona aprovada.</p>
    </div>

    <!-- Label -->
    <div class="mb-3 mt-3 pt-3 border-top">
      <label class="form-label">Label (opcional)</label>
      <input class="form-control form-control-sm" v-model="label" @input="emitLabelUpdate" placeholder="Nome visual do bloco">
    </div>
  </div>
  <div v-else class="node-empty">
    <div class="node-empty__icon">
      <i class="ti ti-pointer"></i>
    </div>
    <h4 class="node-empty__title">Selecione um bloco</h4>
    <p class="node-empty__desc">
      Clique em qualquer bloco no canvas para editar suas propriedades aqui.
    </p>
    <ol class="node-empty__steps">
      <li>
        <span class="node-empty__step-num">1</span>
        Arraste blocos da barra lateral esquerda
      </li>
      <li>
        <span class="node-empty__step-num">2</span>
        Conecte-os puxando das bordas
      </li>
      <li>
        <span class="node-empty__step-num">3</span>
        Configure as propriedades de cada bloco
      </li>
      <li>
        <span class="node-empty__step-num">4</span>
        Defina os gatilhos no rodapé e ative
      </li>
    </ol>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'

const props = defineProps<{ node: { id: string; type: string; data: any } | null }>()
const emit = defineEmits<{
  'update:config': [nodeId: string, config: any]
  'update:label': [nodeId: string, label: string]
}>()

const config = ref<any>({})
const label = ref('')

watch(() => props.node, (n) => {
  if (n) {
    config.value = { ...(n.data?.config ?? getDefaults(n.type)) }
    label.value = n.data?.label ?? ''
  }
}, { immediate: true, deep: true })

function getDefaults(type: string): any {
  const defaults: Record<string, any> = {
    message: { message_type: 'text', text: '' },
    wait: { duration: 1, unit: 'hours' },
    condition: { condition_type: 'replied', keyword: '' },
    tag: { action: 'add', tag: '' },
  }
  return defaults[type] ?? {}
}

function emitUpdate() {
  if (props.node) emit('update:config', props.node.id, { ...config.value })
}
function emitLabelUpdate() {
  if (props.node) emit('update:label', props.node.id, label.value)
}
</script>

<style scoped>
.node-empty {
  height: 100%;
  padding: 24px 20px;
  display: flex;
  flex-direction: column;
  text-align: center;
  color: var(--bc-text-muted);
}
.node-empty__icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: var(--bc-primary-subtle);
  color: var(--bc-primary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.4rem;
  margin: 0 auto 14px;
}
.node-empty__title {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--bc-text);
  margin: 0 0 6px;
}
.node-empty__desc {
  font-size: 0.78rem;
  line-height: 1.5;
  margin: 0 0 18px;
}
.node-empty__steps {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  text-align: left;
}
.node-empty__steps li {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  font-size: 0.78rem;
  color: var(--bc-text);
  line-height: 1.35;
}
.node-empty__step-num {
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: var(--bc-gray-soft);
  color: var(--bc-text-muted);
  font-size: 0.68rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 1px;
}
</style>
