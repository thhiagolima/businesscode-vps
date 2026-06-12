<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Perfil</h2>
    </div>
    <div v-if="$route.query.force_password_reset === '1'" class="alert alert-warning d-flex align-items-center mb-3" style="border-radius:12px">
      <i class="ti ti-key me-2"></i>
      <div>
        <strong>Troca de senha obrigatória.</strong>
        Sua senha foi redefinida pelo administrador. Defina uma nova senha para continuar.
      </div>
    </div>
    <div class="row g-4">
        <!-- Dados pessoais -->
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Dados pessoais</h3></div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label required">Nome</label>
                <input
                  class="form-control"
                  :class="{ 'is-invalid': profileErrors.name }"
                  v-model="form.name"
                  required>
                <div v-if="profileErrors.name" class="invalid-feedback">{{ profileErrors.name }}</div>
              </div>
              <div class="mb-3">
                <label class="form-label required">Email</label>
                <input
                  class="form-control"
                  :class="{ 'is-invalid': profileErrors.email }"
                  type="email"
                  v-model="form.email"
                  required>
                <div v-if="profileErrors.email" class="invalid-feedback">{{ profileErrors.email }}</div>
              </div>
              <button class="btn btn-primary" @click="saveProfile" :disabled="saving || !profileFormValid">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                Salvar alterações
              </button>
            </div>
          </div>
        </div>
        <!-- Alterar senha -->
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header"><h3 class="card-title">Alterar senha</h3></div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label required">Senha atual</label>
                <input
                  class="form-control"
                  :class="{ 'is-invalid': pwErrors.current_password }"
                  type="password"
                  v-model="pw.current_password"
                  required>
                <div v-if="pwErrors.current_password" class="invalid-feedback">{{ pwErrors.current_password }}</div>
              </div>
              <div class="mb-3">
                <label class="form-label required">Nova senha</label>
                <input
                  class="form-control"
                  :class="{ 'is-invalid': pwErrors.password }"
                  type="password"
                  v-model="pw.password"
                  required>
                <div v-if="pwErrors.password" class="invalid-feedback">{{ pwErrors.password }}</div>
              </div>
              <div class="mb-3">
                <label class="form-label required">Confirmar nova senha</label>
                <input
                  class="form-control"
                  :class="{ 'is-invalid': pwErrors.password_confirmation }"
                  type="password"
                  v-model="pw.password_confirmation"
                  required>
                <div v-if="pwErrors.password_confirmation" class="invalid-feedback">{{ pwErrors.password_confirmation }}</div>
              </div>
              <button class="btn btn-primary" @click="changePassword" :disabled="changingPw || !pwFormValid">
                <span v-if="changingPw" class="spinner-border spinner-border-sm me-1"></span>
                Alterar senha
              </button>
            </div>
          </div>
        </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'

const { put } = useApi()
const toast = useToast()
const auth = useAuthStore()

const form = ref({ name: '', email: '' })
const pw = ref({ current_password: '', password: '', password_confirmation: '' })
const saving = ref(false)
const changingPw = ref(false)

const profileErrors = ref<Record<string, string>>({})
const pwErrors = ref<Record<string, string>>({})

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const profileFormValid = computed(() => {
  return !!form.value.name.trim() && EMAIL_RE.test(form.value.email)
})

const pwFormValid = computed(() => {
  return (
    !!pw.value.current_password &&
    pw.value.password.length >= 8 &&
    pw.value.password === pw.value.password_confirmation
  )
})

function validateProfile(): boolean {
  const errors: Record<string, string> = {}
  if (!form.value.name.trim()) {
    errors.name = 'O nome é obrigatório.'
  }
  if (!form.value.email.trim()) {
    errors.email = 'O email é obrigatório.'
  } else if (!EMAIL_RE.test(form.value.email)) {
    errors.email = 'Informe um email válido.'
  }
  profileErrors.value = errors
  return Object.keys(errors).length === 0
}

function validatePassword(): boolean {
  const errors: Record<string, string> = {}
  if (!pw.value.current_password) {
    errors.current_password = 'Informe a senha atual.'
  }
  if (!pw.value.password) {
    errors.password = 'Informe a nova senha.'
  } else if (pw.value.password.length < 8) {
    errors.password = 'A senha deve ter no mínimo 8 caracteres.'
  }
  if (!pw.value.password_confirmation) {
    errors.password_confirmation = 'Confirme a nova senha.'
  } else if (pw.value.password !== pw.value.password_confirmation) {
    errors.password_confirmation = 'As senhas não coincidem.'
  }
  pwErrors.value = errors
  return Object.keys(errors).length === 0
}

onMounted(() => {
  form.value.name = auth.user?.name ?? ''
  form.value.email = auth.user?.email ?? ''
})

async function saveProfile() {
  if (!validateProfile()) return
  saving.value = true
  try {
    const res = await put<any>('/auth/profile', form.value)
    if (res?.user) {
      auth.setUser(res.user)
    }
    toast.success('Perfil atualizado')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao salvar perfil')
  } finally {
    saving.value = false
  }
}

async function changePassword() {
  if (!validatePassword()) return
  changingPw.value = true
  try {
    await put<any>('/auth/password', pw.value)
    // P0R-04: backend revoga TODOS os tokens (inclusive o atual). Avisar usuário
    // e redirecionar para login para evitar "401 fantasmas" em chamadas seguintes.
    toast.success('Senha alterada. Faça login novamente com a nova senha.')
    pw.value = { current_password: '', password: '', password_confirmation: '' }
    pwErrors.value = {}
    // logout() já tenta POST /auth/logout e zera user+token mesmo se o POST falhar
    // (server provavelmente devolverá 401 — token já foi revogado).
    try { await auth.logout() } catch { /* esperado */ }
    window.location.assign('/login')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao alterar senha')
  } finally {
    changingPw.value = false
  }
}
</script>
