<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">
          Preços de Serviços
        </h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">
          Tabela global de custo e venda por serviço.
        </span>
      </div>
      <button class="btn btn-ghost-secondary" :disabled="loading" @click="reload">
        <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
        <i v-else class="ti ti-refresh me-1"></i>
        Atualizar
      </button>
    </div>

    <div class="alert alert-warning mb-3" role="alert">
      <i class="ti ti-alert-triangle me-1"></i>
      <strong>Atenção:</strong> alterações afetam APENAS novos envios. Dispatches já cobrados
      não são reembolsados.
    </div>

    <div class="card" style="border-radius:14px">
      <div v-if="loading && !store.prices.length" class="card-body text-center py-5">
        <span class="spinner-border spinner-border-sm"></span>
      </div>

      <div v-else-if="!store.prices.length" class="card-body text-center py-5">
        <i class="ti ti-cash-off" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
        <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">
          Nenhum preço configurado
        </h3>
        <p style="font-size:0.85rem;color:var(--bc-text-muted)">
          Execute o seeder ServicePricesSeeder no backend.
        </p>
      </div>

      <div v-else class="table-responsive">
        <table class="table card-table table-vcenter">
          <thead>
            <tr>
              <th>Serviço</th>
              <th class="text-end">Custo</th>
              <th class="text-end">Venda</th>
              <th class="text-end">Margem</th>
              <th class="text-end">Margem %</th>
              <th>Última alteração</th>
              <th class="text-end">Ações</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in store.prices" :key="p.service">
              <td>
                <span style="font-weight:600">{{ serviceLabel(p.service) }}</span>
                <div style="font-size:0.7rem;color:var(--bc-text-muted);font-family:'JetBrains Mono',monospace">
                  {{ p.service }}
                </div>
              </td>
              <td class="text-end" style="font-family:'JetBrains Mono',monospace">
                R$ {{ formatPrice(p.cost_micros, p.cost_cents) }}
              </td>
              <td class="text-end fw-bold" style="font-family:'JetBrains Mono',monospace">
                R$ {{ formatPrice(p.sale_micros, p.sale_cents) }}
              </td>
              <td
                class="text-end"
                style="font-family:'JetBrains Mono',monospace"
                :class="{ 'text-danger': (p.margin_micros ?? p.margin_cents ?? 0) < 0 }"
              >
                R$ {{ formatPrice(p.margin_micros, p.margin_cents) }}
              </td>
              <td
                class="text-end"
                :class="{ 'text-danger': (p.margin_percent ?? 0) < 0 }"
              >
                {{ Number(p.margin_percent ?? 0).toFixed(1) }}%
              </td>
              <td style="font-size:0.78rem;color:var(--bc-text-muted)">
                {{ formatDate(p.updated_at) }}
                <div v-if="p.updated_by_name">por {{ p.updated_by_name }}</div>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-primary" @click="edit(p)">
                  <i class="ti ti-pencil me-1"></i>
                  Editar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <PriceEditModal
      v-if="editing"
      :price="editing"
      mode="global"
      @close="editing = null"
      @saved="onSaved"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useBillingStore, type ServicePrice } from '@/stores/billing'
import { useToast } from '@/composables/useToast'
import PriceEditModal from '@/components/billing/PriceEditModal.vue'

const store = useBillingStore()
const toast = useToast()

const editing = ref<ServicePrice | null>(null)
const loading = ref(false)

// Prefer micros (1 micro = R$ 0,00001) for sub-cent precision.
// Falls back to cents for any service where micros aren't sent yet.
// Always shows 2 decimals minimum; preserves up to 4 if the extra digits are significant.
// Examples: 0,0605 -> "0,0605" | 0,0750 -> "0,075" | 0,0700 -> "0,07" | 0,03501 -> "0,035"
function formatPrice(micros: number | null | undefined, cents?: number | null): string {
  const m = Number(micros ?? 0)
  const reais = m > 0 ? m / 100000 : Number(cents ?? 0) / 100
  const sign = reais < 0 ? '-' : ''
  const abs = Math.abs(reais).toFixed(4) // e.g. "0.0750"
  const match = abs.match(/^(\d+)\.(\d{2})(\d{2})$/)
  if (!match) return sign + abs.replace('.', ',')
  const [, intPart, twoDec, extra] = match
  const trimmed = extra.replace(/0+$/, '') // strip only the trailing zeros, keep significant digits
  const decimals = twoDec + trimmed
  return sign + intPart + ',' + decimals
}

function serviceLabel(s: string): string {
  const map: Record<string, string> = {
    sms: 'SMS',
    voice: 'Voz (TTS)',
    email: 'Email',
    ai_generation: 'Geração IA',
    audio_tts: 'Áudio TTS',
  }
  return map[s] || s
}

function formatDate(iso?: string): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  return d.toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

async function reload() {
  loading.value = true
  try {
    await store.loadPrices()
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao carregar preços')
  } finally {
    loading.value = false
  }
}

function edit(p: ServicePrice) {
  editing.value = p
}

function onSaved() {
  editing.value = null
}

onMounted(reload)
</script>
