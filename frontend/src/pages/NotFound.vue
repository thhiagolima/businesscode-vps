<template>
  <div class="text-center py-5">
    <div class="mb-3" style="font-size:4rem;color:var(--bc-text-muted)">404</div>
    <h2>Página não encontrada</h2>
    <p class="text-muted">A página que você procura não existe ou foi movida.</p>
    <button class="btn btn-primary" @click="$router.push('/dashboard')">
      <i class="ti ti-arrow-left me-1"></i> Voltar ao dashboard
    </button>
  </div>
</template>
