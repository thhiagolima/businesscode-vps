import { ref } from 'vue'
import axios from 'axios'

/**
 * Identidade pública da plataforma (white-label do tenant operador).
 * Consultada em GET /api/v1/public/identity.
 *
 * O composable cacheia em memória: a primeira chamada dispara o fetch,
 * as próximas reusam o mesmo ref reativo. NÃO usar fallback "BusinessCode"
 * — se o backend não responder, o consumidor deve mostrar loading/"—".
 */

export interface PlatformIdentity {
  company_name: string | null
  brand_name: string | null
  legal_name: string | null
  cnpj: string | null
  support_email: string | null
  sales_whatsapp: string | null
  sales_whatsapp_prompt: string | null
  site_url: string | null
  docs_url: string | null
  terms_version: string | null
  privacy_version: string | null
}

const identity = ref<PlatformIdentity | null>(null)
const loading = ref(false)
const error = ref(false)
let inflight: Promise<void> | null = null

async function loadIdentity(): Promise<void> {
  if (identity.value !== null) return
  if (inflight) return inflight
  loading.value = true
  error.value = false
  inflight = (async () => {
    try {
      const res = await axios.get('/api/v1/public/identity')
      identity.value = (res.data ?? null) as PlatformIdentity | null
    } catch {
      error.value = true
      identity.value = null
    } finally {
      loading.value = false
      inflight = null
    }
  })()
  return inflight
}

export function useIdentity() {
  if (identity.value === null && !inflight) {
    // fire-and-forget; consumers react via the ref
    void loadIdentity()
  }
  return {
    identity,
    loading,
    error,
    reload: () => {
      identity.value = null
      return loadIdentity()
    },
  }
}
