<template>
  <div style="min-height:80vh;display:flex;align-items:center;justify-content:center;padding:2rem">
    <div style="max-width:700px;width:100%;text-align:center">
      <div style="width:80px;height:80px;border-radius:50%;background:rgba(16,185,129,0.1);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1.5rem">
        <i class="ti ti-circle-check" style="font-size:2.5rem;color:#10b981"></i>
      </div>

      <h1 style="font-size:2rem;font-weight:800;letter-spacing:-.03em;margin-bottom:.5rem">
        <template v-if="planName">Bem-vindo ao plano {{ planName }}!</template>
        <template v-else>Pagamento confirmado!</template>
      </h1>
      <p style="font-size:1rem;color:var(--bc-text-muted);max-width:500px;margin:0 auto 2rem">
        Sua assinatura está ativa. Você desbloqueou todos os recursos do plano.
      </p>

      <div class="row g-3" style="text-align:left;margin-top:2rem">
        <div class="col-12 col-md-4">
          <router-link to="/campaigns/new" class="bc-next-card">
            <i class="ti ti-rocket" style="font-size:1.5rem;color:#0064ff;margin-bottom:.75rem;display:block"></i>
            <div style="font-weight:700;margin-bottom:.25rem">Criar Campanha</div>
            <div style="font-size:.82rem;color:var(--bc-text-muted)">Lance sua primeira campanha multi-canal.</div>
          </router-link>
        </div>
        <div class="col-12 col-md-4">
          <router-link to="/contacts" class="bc-next-card">
            <i class="ti ti-users-plus" style="font-size:1.5rem;color:#0064ff;margin-bottom:.75rem;display:block"></i>
            <div style="font-weight:700;margin-bottom:.25rem">Importar Contatos</div>
            <div style="font-size:.82rem;color:var(--bc-text-muted)">Sincronize seu CRM ou importe via CSV.</div>
          </router-link>
        </div>
        <div class="col-12 col-md-4">
          <router-link to="/dashboard" class="bc-next-card">
            <i class="ti ti-layout-dashboard" style="font-size:1.5rem;color:#0064ff;margin-bottom:.75rem;display:block"></i>
            <div style="font-weight:700;margin-bottom:.25rem">Ver Dashboard</div>
            <div style="font-size:.82rem;color:var(--bc-text-muted)">Monitore métricas e performance.</div>
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

// Resolve o nome do plano APENAS a partir de fontes verificáveis:
// 1) query string ?plan=... (vinda do redirect do checkout)
// 2) plano corrente do tenant logado
// NUNCA usar fallback fake ("Pro"). Se nada vier, mostramos uma saudação genérica.
const planName = computed<string | null>(() => {
  const fromQuery = (route.query.plan as string | undefined) ?? null
  if (fromQuery && fromQuery.trim() !== '') return fromQuery.trim()
  const fromUser = (auth.user as any)?.tenant?.plan?.name ?? null
  return fromUser && String(fromUser).trim() !== '' ? String(fromUser) : null
})
</script>

<style scoped>
.bc-next-card {
  display: block;
  background: var(--bc-gray, rgba(22, 27, 69, 0.4));
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px;
  padding: 20px;
  text-decoration: none;
  color: inherit;
  transition: all .2s;
  height: 100%;
}
.bc-next-card:hover {
  transform: translateY(-2px);
  border-color: rgba(0,100,255,0.2);
  box-shadow: 0 4px 16px rgba(0,0,0,.2);
}
</style>
