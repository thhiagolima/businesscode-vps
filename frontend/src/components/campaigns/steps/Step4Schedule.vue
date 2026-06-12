<template>
  <div>
    <div class="text-center mb-4">
      <h3 style="font-size:1.1rem;font-weight:700;color:var(--bc-text)">Quando enviar?</h3>
      <p style="font-size:0.85rem;color:var(--bc-text-muted)">Escolha o momento ideal para sua campanha</p>
    </div>

    <!-- Option cards -->
    <div class="row g-3 justify-content-center" style="max-width:640px;margin:0 auto">
      <div class="col-6">
        <div class="bc-schedule-card" :class="{ active: mode === 'now' }" @click="mode = 'now'" tabindex="0" role="radio" :aria-checked="mode === 'now'">
          <div class="bc-schedule-icon" :class="mode === 'now' ? 'bc-icon-active' : ''">
            <i class="ti ti-send" style="font-size:1.5rem"></i>
          </div>
          <div class="bc-schedule-title">Enviar agora</div>
          <div class="bc-schedule-desc">Disparo imediato após confirmar</div>
          <div v-if="mode === 'now'" class="bc-schedule-check">
            <i class="ti ti-circle-check-filled"></i>
          </div>
        </div>
      </div>
      <div class="col-6">
        <div class="bc-schedule-card" :class="{ active: mode === 'schedule' }" @click="mode = 'schedule'" tabindex="0" role="radio" :aria-checked="mode === 'schedule'">
          <div class="bc-schedule-icon" :class="mode === 'schedule' ? 'bc-icon-active' : ''">
            <i class="ti ti-calendar-event" style="font-size:1.5rem"></i>
          </div>
          <div class="bc-schedule-title">Agendar</div>
          <div class="bc-schedule-desc">Defina data e horário</div>
          <div v-if="mode === 'schedule'" class="bc-schedule-check">
            <i class="ti ti-circle-check-filled"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Schedule details -->
    <transition name="bc-slide">
      <div v-if="mode === 'schedule'" class="mt-4" style="max-width:440px;margin-left:auto;margin-right:auto">
        <div class="card" style="border-radius:12px">
          <div class="card-body p-3">
            <label class="form-label fw-medium" style="font-size:0.85rem">
              <i class="ti ti-clock me-1 text-primary"></i>Data e hora do envio
            </label>
            <input
              type="datetime-local"
              class="form-control"
              v-model="value"
              @change="validate"
              :class="{ 'is-invalid': error !== '' }"
              style="border-radius:8px;font-size:0.9rem"
            />
            <div class="invalid-feedback" v-if="error">{{ error }}</div>
            <div class="d-flex align-items-center gap-2 mt-2" style="font-size:0.78rem;color:var(--bc-text-muted,#6c7293)">
              <i class="ti ti-info-circle"></i>
              <span>Horário de Brasília (GMT-3) · Mínimo 5 minutos no futuro</span>
            </div>

            <!-- Preview da data formatada -->
            <div v-if="value && !error" class="mt-3 p-2 d-flex align-items-center gap-2" style="background:rgba(0,100,255,0.04);border-radius:8px">
              <i class="ti ti-calendar-check text-primary"></i>
              <span style="font-size:0.85rem;font-weight:500;color:var(--bc-text,#1a1d2e)">
                {{ formattedDate }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </transition>

    <!-- Now mode info -->
    <transition name="bc-slide">
      <div v-if="mode === 'now'" class="mt-4 text-center">
        <div class="d-inline-flex align-items-center gap-2 px-3 py-2" style="background:rgba(16,185,129,0.06);border-radius:8px;font-size:0.85rem;color:#0d9668">
          <i class="ti ti-bolt"></i>
          <span>A campanha será disparada assim que você confirmar no próximo passo</span>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

interface Props {
  initialMode?: 'now' | 'schedule'
  initialDateTime?: string | null
}
const props = defineProps<Props>()
const emit = defineEmits<{ update: [{ mode: 'now' | 'schedule'; datetime: string | null; valid: boolean }] }>()

const mode = ref<'now' | 'schedule'>(props.initialMode ?? 'now')
const value = ref<string>(props.initialDateTime ?? '')
const error = ref<string>('')

const isValid = computed(() => {
  if (mode.value === 'now') return true
  if (!value.value) return false
  const selected = new Date(value.value).getTime()
  const min = Date.now() + 5 * 60 * 1000
  return selected >= min
})

const formattedDate = computed(() => {
  if (!value.value) return ''
  try {
    const d = new Date(value.value)
    return d.toLocaleDateString('pt-BR', {
      weekday: 'long', day: '2-digit', month: 'long', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    })
  } catch { return value.value }
})

function validate() {
  if (mode.value === 'now') { error.value = ''; return }
  if (!value.value) { error.value = 'Informe data e hora.'; return }
  const selected = new Date(value.value).getTime()
  const min = Date.now() + 5 * 60 * 1000
  error.value = selected < min ? 'A data deve ser pelo menos 5 minutos no futuro.' : ''
}

watch([mode, value, isValid], () => {
  emit('update', { mode: mode.value, datetime: mode.value === 'schedule' ? value.value : null, valid: isValid.value })
})
</script>

<style scoped>
.bc-schedule-card {
  position: relative;
  padding: 1.5rem 1rem;
  border: 2px solid var(--bc-gray-soft);
  border-radius: 14px;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s ease;
  background: var(--bc-gray);
}
.bc-schedule-card:hover {
  border-color: rgba(0, 100, 255, 0.3);
  box-shadow: 0 2px 12px rgba(0, 100, 255, 0.15);
}
.bc-schedule-card.active {
  border-color: #0064ff;
  background: rgba(0, 100, 255, 0.08);
  box-shadow: 0 2px 16px rgba(0, 100, 255, 0.1);
}
.bc-schedule-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 0.75rem;
  background: rgba(0, 100, 255, 0.06);
  color: var(--bc-text-muted, #6c7293);
  transition: all 0.2s ease;
}
.bc-icon-active {
  background: rgba(0, 100, 255, 0.1);
  color: #0064ff;
}
.bc-schedule-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--bc-text, #1a1d2e);
  margin-bottom: 0.25rem;
}
.bc-schedule-desc {
  font-size: 0.78rem;
  color: var(--bc-text-muted, #6c7293);
}
.bc-schedule-check {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  color: #0064ff;
  font-size: 1.2rem;
}

/* Transition */
.bc-slide-enter-active { transition: all 0.25s ease-out; }
.bc-slide-leave-active { transition: all 0.15s ease-in; }
.bc-slide-enter-from { opacity: 0; transform: translateY(-8px); }
.bc-slide-leave-to { opacity: 0; transform: translateY(-4px); }

/* Dark mode */
[data-bs-theme="dark"] .bc-schedule-card { background: #1a1d2e; border-color: #2a2d3e; }
[data-bs-theme="dark"] .bc-schedule-card.active { background: rgba(0,100,255,0.08); border-color: #0064ff; }
[data-bs-theme="dark"] .bc-schedule-icon { background: rgba(0,100,255,0.1); }

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
  .bc-slide-enter-active, .bc-slide-leave-active { transition: none; }
  .bc-schedule-card { transition: none; }
}
</style>
