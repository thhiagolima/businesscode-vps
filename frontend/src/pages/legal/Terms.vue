<template>
  <div style="max-width:800px;margin:0 auto;padding:2rem 1rem">
    <h1 style="font-size:1.6rem;font-weight:700;margin-bottom:1rem">Termos de Uso</h1>
    <p style="color:var(--bc-text-muted);margin-bottom:2rem">
      <template v-if="identity?.terms_version">Versão {{ identity.terms_version }}</template>
      <template v-else-if="!identityLoading">—</template>
    </p>
    <div style="line-height:1.8;color:var(--bc-text)">
      <h2 style="font-size:1.1rem;font-weight:600;margin:1.5rem 0 0.5rem">1. Aceitação dos Termos</h2>
      <p>
        Ao utilizar a plataforma
        <template v-if="brand">{{ brand }}</template><template v-else>—</template>,
        você concorda com estes termos de uso.
      </p>
      <h2 style="font-size:1.1rem;font-weight:600;margin:1.5rem 0 0.5rem">2. Uso da Plataforma</h2>
      <p>
        <template v-if="brand">{{ brand }}</template><template v-else>A plataforma</template>
        é uma solução de automação de marketing multicanal. O uso indevido para spam ou mensagens não solicitadas é estritamente proibido.
      </p>
      <h2 style="font-size:1.1rem;font-weight:600;margin:1.5rem 0 0.5rem">3. Créditos e Cobrança</h2>
      <p>Os créditos são consumidos por envio de mensagem. Créditos não utilizados não são reembolsáveis.</p>
      <h2 style="font-size:1.1rem;font-weight:600;margin:1.5rem 0 0.5rem">4. Privacidade</h2>
      <p>Consulte nossa <a href="/privacy" style="color:var(--bc-primary)">Política de Privacidade</a> para informações sobre coleta e uso de dados.</p>
      <h2 style="font-size:1.1rem;font-weight:600;margin:1.5rem 0 0.5rem">5. Contato</h2>
      <p>
        Para dúvidas, entre em contato
        <template v-if="identity?.support_email">pelo e-mail
          <a :href="`mailto:${identity.support_email}`" style="color:var(--bc-primary)">{{ identity.support_email }}</a>.
        </template>
        <template v-else>com o administrador da plataforma.</template>
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useIdentity } from '@/composables/useIdentity'

const { identity, loading: identityLoading } = useIdentity()
const brand = computed(() => identity.value?.brand_name ?? identity.value?.legal_name ?? null)
</script>
