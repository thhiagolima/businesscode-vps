import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useApi } from '@/composables/useApi'

type UserRole = 'user' | 'admin' | 'superadmin' | 'finance' | string

type User = {
  id: number
  name: string
  email: string
  role: UserRole
  force_password_reset?: boolean
  status?: 'active' | 'suspended'
  tenant?: {
    id?: number
    name?: string
    status?: string
    balance_cents?: number
    credit_limit_cents?: number
    billing_status?: string
    billing_cycle_day?: number | null
  } | null
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const token = ref<string | null>(null)
  const isLoading = ref<boolean>(false)

  const isAuthenticated = computed(() => !!token.value)

  const login = async (email: string, password: string) => {
    isLoading.value = true
    try {
      const { post } = useApi()
      const resp = await post<{ user: User; token: string }>('/auth/login', { email, password })
      user.value = resp.user
      token.value = resp.token
      return true
    } finally {
      isLoading.value = false
    }
  }

  const clearSession = () => {
    user.value = null
    token.value = null
  }

  const logout = async () => {
    const hadToken = !!token.value
    const { post } = useApi()
    const logoutRequest = hadToken ? post('/auth/logout') : Promise.resolve()

    clearSession()

    if (!hadToken) return

    try {
      await Promise.race([
        logoutRequest,
        new Promise(resolve => setTimeout(resolve, 1500)),
      ])
    } catch (_) {}
  }

  const initialize = async () => {
    if (!token.value) return
    try {
      const { get } = useApi()
      const resp = await get<{ user: User }>('/auth/me')
      user.value = resp.user
    } catch {
      // Keep persisted user data if API is unavailable
    }
  }

  function setUser(userData: any) {
    if (userData?.user && userData?.token) {
      user.value = userData.user
      token.value = userData.token
    } else {
      user.value = userData
    }
  }

  function updateCredits(balanceCents: number) {
    if (user.value?.tenant) {
      user.value.tenant.balance_cents = balanceCents
    }
  }

  // Refresh user data from /auth/me — call periodically to keep balance_cents fresh
  const refreshUser = async () => {
    if (!token.value) return
    try {
      const { get } = useApi()
      const resp = await get<{ user: User }>('/auth/me')
      user.value = resp.user
    } catch {}
  }

  return { user, token, isLoading, isAuthenticated, login, logout, clearSession, initialize, setUser, updateCredits, refreshUser }
}, {
  persist: {
    paths: ['token', 'user'],
  },
})
