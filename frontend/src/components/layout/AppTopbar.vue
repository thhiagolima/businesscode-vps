<template>
  <header class="bc-topbar">
    <div class="container-xl d-flex align-items-center gap-3">
      <!-- Mobile hamburger -->
      <button class="navbar-toggler d-lg-none border-0 p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobile-drawer" aria-label="Menu">
        <i class="ti ti-menu-2" style="font-size:1.2rem;color:var(--bc-text)"></i>
      </button>

      <!-- Search bar -->
      <div class="flex-fill" style="max-width:480px">
        <div class="position-relative">
          <i class="ti ti-search position-absolute" style="left:12px;top:50%;transform:translateY(-50%);color:var(--bc-text-muted);font-size:0.9rem"></i>
          <input type="text" class="form-control" placeholder="Buscar campanhas, contatos ou configurações..."
            style="padding-left:36px;background:var(--bc-gray-soft);border:none;border-radius:9999px;height:38px;font-size:0.82rem">
        </div>
      </div>

      <!-- Right side -->
      <div class="d-flex align-items-center gap-3 ms-auto">
        <!-- Dark mode toggle -->
        <button class="bc-topbar-btn" @click="toggleTheme" :title="theme === 'dark' ? 'Modo claro' : 'Modo escuro'">
          <i :class="theme === 'dark' ? 'ti ti-sun' : 'ti ti-moon'" style="font-size:1rem"></i>
        </button>

        <!-- Notifications -->
        <button class="bc-topbar-btn position-relative">
          <i class="ti ti-bell" style="font-size:1rem"></i>
        </button>

        <!-- User -->
        <div class="nav-item dropdown">
          <a href="#" class="d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown" data-bc-user-menu>
            <span class="avatar avatar-sm"
              style="background:linear-gradient(135deg,#0064ff,#0054d8);color:#fff;font-size:0.7rem;font-weight:600;width:34px;height:34px;border-radius:10px">
              {{ userInitials }}
            </span>
            <div class="d-none d-xl-block">
              <div style="font-weight:600;font-size:0.82rem;color:var(--bc-text)">{{ userName }}</div>
              <div style="font-size:0.68rem;color:var(--bc-text-muted)">{{ userRole }}</div>
            </div>
          </a>
          <div class="dropdown-menu dropdown-menu-end"
            style="min-width:200px;background:var(--bc-gray);border:none;border-radius:var(--bc-radius-lg)">
            <button type="button" class="dropdown-item" @click="goProfile">
              <i class="ti ti-user me-2"></i> Meu perfil
            </button>
            <button type="button" class="dropdown-item" @click="goSettings">
              <i class="ti ti-settings me-2"></i> Configurações
            </button>
            <div class="dropdown-divider" style="border-color:var(--bc-outline)"></div>
            <button type="button" class="dropdown-item text-danger" :disabled="isLoggingOut" @click="logout">
              <i class="ti ti-logout me-2"></i> Sair
            </button>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { theme, toggle: toggleTheme } = useTheme()
const isLoggingOut = ref(false)

const userName = computed(() => auth.user?.name ?? '')
const userEmail = computed(() => auth.user?.email ?? '')
const ROLE_LABELS: Record<string, string> = {
  superadmin: 'Administrador',
  admin: 'Administrador',
  finance: 'Financeiro',
  agent: 'Operador',
  member: 'Membro',
}
const userRole = computed(() => {
  const role = auth.user?.role
  if (!role) return 'Usuário'
  return ROLE_LABELS[role] ?? 'Usuário'
})
const userInitials = computed(() => {
  const name = userName.value || userEmail.value
  const parts = (name ?? '').trim().split(' ')
  return ((parts[0]?.charAt(0) ?? '') + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase()
})

const hideUserDropdown = () => {
  const toggle = document.querySelector<HTMLElement>('[data-bc-user-menu]')
  if (!toggle) return
  const instance = (window as any).bootstrap?.Dropdown?.getInstance(toggle)
  instance?.hide()
}

const goProfile = () => {
  hideUserDropdown()
  router.push('/profile')
}

const goSettings = () => {
  hideUserDropdown()
  router.push(auth.user?.role === 'superadmin' ? '/admin/settings/ai' : '/profile')
}

const logout = async () => {
  if (isLoggingOut.value) return
  isLoggingOut.value = true
  hideUserDropdown()
  const pendingLogout = auth.logout()
  await router.replace('/login')
  pendingLogout.finally(() => {
    isLoggingOut.value = false
  })
}
</script>

<style scoped>
.bc-topbar {
  background: transparent;
  border-bottom: none;
  padding: 0.75rem 0;
  position: sticky;
  top: 0;
  z-index: 1030;
}
.bc-topbar-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: none;
  background: var(--bc-gray-soft);
  color: var(--bc-text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
}
.bc-topbar-btn:hover {
  background: var(--bc-primary-subtle);
  color: var(--bc-primary);
}
.dropdown-item {
  color: var(--bc-text-muted);
  border-radius: 6px;
  margin: 2px 4px;
  font-size: 0.85rem;
}
.dropdown-item:hover {
  background: var(--bc-primary-subtle);
  color: var(--bc-white);
}
@media (max-width: 991px) {
  .bc-topbar { padding: 0.5rem 0; }
}
</style>
