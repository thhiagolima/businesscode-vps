<template>
  <div class="page">
    <!-- Desktop: sidebar fixo -->
    <aside class="navbar navbar-vertical navbar-expand-lg d-none d-lg-flex" data-bs-theme="dark">
      <AppSidebar />
    </aside>

    <!-- Mobile: offcanvas drawer -->
    <div class="offcanvas offcanvas-start d-lg-none" tabindex="-1" id="mobile-drawer" data-bs-theme="dark"
         style="width:280px;background:var(--bc-dark-surface,#161a33);overflow-y:auto;padding-bottom:env(safe-area-inset-bottom,0)">
      <div class="offcanvas-body p-0 d-flex flex-column" style="min-height:100%">
        <AppSidebar @navigate="closeDrawer" />
      </div>
    </div>

    <div class="page-wrapper">
      <BalanceBanner v-if="auth.isAuthenticated" />
      <AppTopbar />
      <div class="page-body">
        <div class="container-xl">
          <RouterView />
        </div>
      </div>
      <footer class="footer footer-transparent d-print-none">
        <div class="container-xl">
          <div class="text-center py-2" style="display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap">
            <span style="font-size:0.72rem;color:var(--bc-text-muted);opacity:0.4">
              © {{ new Date().getFullYear() }}
              <template v-if="identity?.legal_name">{{ identity.legal_name }}</template>
              <template v-else-if="identity?.brand_name">{{ identity.brand_name }}</template>
              <template v-else-if="!identityLoading">—</template>
            </span>
            <a href="/terms" style="font-size:0.72rem;color:var(--bc-text-muted);opacity:0.4;text-decoration:none">Termos</a>
            <a href="/privacy" style="font-size:0.72rem;color:var(--bc-text-muted);opacity:0.4;text-decoration:none">Privacidade</a>
          </div>
        </div>
      </footer>
    </div>
  </div>
</template>

<script setup lang="ts">
import AppSidebar from './AppSidebar.vue'
import AppTopbar from './AppTopbar.vue'
import BalanceBanner from '@/components/billing/BalanceBanner.vue'
import { useIdentity } from '@/composables/useIdentity'
import { useAuthStore } from '@/stores/auth'

const { identity, loading: identityLoading } = useIdentity()
const auth = useAuthStore()

function closeDrawer() {
  const el = document.getElementById('mobile-drawer')
  if (el) {
    const offcanvas = (window as any).bootstrap?.Offcanvas?.getInstance(el)
    offcanvas?.hide()
  }
}
</script>
