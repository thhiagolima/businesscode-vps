<template>
  <div class="login-page">
    <!-- Left: Brand panel -->
    <div class="login-brand">
      <div class="brand-content">
        <div class="brand-logo">
          <svg width="48" height="48" viewBox="0 0 64 64">
            <rect width="64" height="64" rx="14" fill="#0064ff"/>
            <path d="M16 18C16 14 20 10 24 10H40C44 10 48 14 48 18V34C48 38 44 42 40 42H28L20 50V42H24C20 42 16 38 16 34Z" fill="#fff"/>
            <path d="M20 14C20 12 22 10 24 10H44C48 10 52 14 52 18V30C52 34 48 38 44 38" fill="none" stroke="rgba(255,255,255,0.4)" stroke-width="3" stroke-linecap="round"/>
          </svg>
        </div>
        <h1 class="brand-title">Business<span>Code</span></h1>
        <p class="brand-tagline">Plataforma inteligente de comunicação multicanal com automação e IA.</p>
        <div class="brand-features">
          <div class="feature-item">
            <i class="ti ti-message-circle"></i>
            <span>SMS, Voz, Email e WhatsApp</span>
          </div>
          <div class="feature-item">
            <i class="ti ti-robot"></i>
            <span>Chatbot com IA conversacional</span>
          </div>
          <div class="feature-item">
            <i class="ti ti-chart-funnel"></i>
            <span>Funis visuais automatizados</span>
          </div>
        </div>
      </div>
      <div class="brand-decoration">
        <div class="decoration-circle c1"></div>
        <div class="decoration-circle c2"></div>
        <div class="decoration-circle c3"></div>
      </div>
    </div>

    <!-- Right: Form panel -->
    <div class="login-form-panel">
      <div class="form-container">
        <div class="form-header">
          <h2>Bem-vindo de volta</h2>
          <p>Entre com suas credenciais para acessar a plataforma.</p>
        </div>
        <form @submit.prevent="submit">
          <div class="field">
            <label>Email</label>
            <div class="input-wrapper">
              <i class="ti ti-mail"></i>
              <input v-model="email" type="email" placeholder="voce@empresa.com" required autofocus />
            </div>
          </div>
          <div class="field">
            <label>Senha</label>
            <div class="input-wrapper">
              <i class="ti ti-lock"></i>
              <input v-model="password" type="password" placeholder="••••••••" required />
            </div>
          </div>
          <button type="submit" class="submit-btn" :disabled="auth.isLoading">
            <span v-if="auth.isLoading" class="spinner"></span>
            <span v-else>
              Entrar
              <i class="ti ti-arrow-right"></i>
            </span>
          </button>
        </form>
        <a href="#" class="forgot-link" @click.prevent="$router.push('/auth/forgot-password')">
          Esqueceu sua senha?
        </a>
        <div class="auth-separator">
          <span>ou</span>
        </div>
        <a href="#" class="register-link" @click.prevent="goToRegister">
          Criar conta grátis →
        </a>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const toast = useToast()

const email = ref('')
const password = ref('')

function safeRedirect(target: string | undefined): string {
  // Only allow internal paths (must start with /, not //)
  if (target && target.startsWith('/') && !target.startsWith('//')) return target
  return '/dashboard'
}

const submit = async () => {
  try {
    const ok = await auth.login(email.value, password.value)
    if (ok) {
      const redirect = route.query.redirect as string | undefined
      router.push(safeRedirect(redirect))
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Credenciais inválidas')
  }
}

function goToRegister() {
  const redirect = route.query.redirect as string | undefined
  router.push({ path: '/register', query: redirect ? { redirect } : {} })
}
</script>

<style scoped>
.login-page {
  display: flex;
  min-height: 100vh;
  background: var(--bc-dark-lighter);
}

/* ─── Brand Panel ─── */
.login-brand {
  flex: 0 0 45%;
  background: linear-gradient(135deg, #080c25 0%, #0f1338 50%, #080c25 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  padding: 3rem;
}
.brand-content {
  position: relative;
  z-index: 2;
  max-width: 420px;
}
.brand-logo {
  margin-bottom: 1.5rem;
  animation: fadeInUp 0.6s ease-out;
}
.brand-title {
  font-family: 'Manrope', sans-serif;
  font-size: 2.6rem;
  font-weight: 700;
  color: #fff;
  letter-spacing: -0.04em;
  margin: 0 0 0.75rem;
  animation: fadeInUp 0.6s ease-out 0.1s both;
}
.brand-title span {
  color: #0064ff;
}
.brand-tagline {
  color: rgba(255, 255, 255, 0.5);
  font-size: 1.05rem;
  line-height: 1.6;
  margin-bottom: 2.5rem;
  animation: fadeInUp 0.6s ease-out 0.2s both;
}
.brand-features {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  animation: fadeInUp 0.6s ease-out 0.3s both;
}
.feature-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  color: rgba(255, 255, 255, 0.6);
  font-size: 0.9rem;
}
.feature-item i {
  color: #0064ff;
  font-size: 1.1rem;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 100, 255, 0.12);
  border-radius: 8px;
  flex-shrink: 0;
}

/* Decorative circles */
.brand-decoration { position: absolute; inset: 0; pointer-events: none; }
.decoration-circle {
  position: absolute;
  border-radius: 50%;
  border: 1px solid rgba(0, 100, 255, 0.08);
}
.c1 { width: 400px; height: 400px; top: -100px; right: -100px; animation: float 20s infinite; }
.c2 { width: 300px; height: 300px; bottom: -50px; left: -80px; animation: float 15s infinite reverse; }
.c3 { width: 200px; height: 200px; top: 50%; left: 60%; border-color: rgba(0, 100, 255, 0.05); animation: float 25s infinite; }

@keyframes float {
  0%, 100% { transform: translate(0, 0); }
  25% { transform: translate(10px, -15px); }
  50% { transform: translate(-5px, 10px); }
  75% { transform: translate(15px, 5px); }
}
@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

/* ─── Form Panel ─── */
.login-form-panel {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  background: var(--bc-dark-lighter);
}
.form-container {
  width: 100%;
  max-width: 380px;
  animation: fadeInUp 0.5s ease-out 0.2s both;
}
.form-header {
  margin-bottom: 2rem;
}
.form-header h2 {
  font-family: 'Manrope', sans-serif;
  font-size: 1.7rem;
  font-weight: 700;
  color: var(--bc-text);
  letter-spacing: -0.03em;
  margin: 0 0 0.5rem;
}
.form-header p {
  color: var(--bc-text-muted);
  font-size: 0.9rem;
  margin: 0;
}

.field {
  margin-bottom: 1.25rem;
}
.field label {
  display: block;
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--bc-text-muted);
  margin-bottom: 0.4rem;
}
.input-wrapper {
  display: flex;
  align-items: center;
  background: var(--bc-gray-soft);
  border: 1.5px solid transparent;
  border-radius: 10px;
  padding: 0 1rem;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.input-wrapper:focus-within {
  border-color: #0064ff;
  box-shadow: 0 0 0 3px rgba(0, 100, 255, 0.12);
}
.input-wrapper i {
  color: var(--bc-text-muted);
  font-size: 1.1rem;
  margin-right: 0.75rem;
  transition: color 0.2s;
}
.input-wrapper:focus-within i {
  color: #0064ff;
}
.input-wrapper input {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  padding: 0.75rem 0;
  font-family: 'Inter', sans-serif;
  font-size: 0.9rem;
  color: var(--bc-text);
}
.input-wrapper input::placeholder {
  color: var(--bc-text-muted);
  opacity: 0.5;
}

.submit-btn {
  width: 100%;
  padding: 0.85rem 1.5rem;
  background: #0064ff;
  color: #fff;
  border: none;
  border-radius: 10px;
  font-family: 'Inter', sans-serif;
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 4px 16px rgba(0, 100, 255, 0.3);
  margin-top: 0.5rem;
}
.submit-btn:hover:not(:disabled) {
  background: #0052d4;
  transform: translateY(-1px);
  box-shadow: 0 6px 24px rgba(0, 100, 255, 0.4);
}
.submit-btn:active:not(:disabled) {
  transform: translateY(0);
}
.submit-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}
.submit-btn .spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.forgot-link {
  display: block;
  text-align: center;
  margin-top: 1.25rem;
  color: var(--bc-text-muted);
  font-size: 0.82rem;
  text-decoration: none;
  transition: color 0.2s;
}
.forgot-link:hover {
  color: #0064ff;
}

.auth-separator {
  text-align: center;
  margin: 20px 0;
  position: relative;
}
.auth-separator::before {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  top: 50%;
  height: 1px;
  background: var(--bc-gray-soft);
}
.auth-separator span {
  background: var(--bc-dark-lighter);
  padding: 0 12px;
  font-size: 0.78rem;
  color: var(--bc-text-muted);
  position: relative;
}
.register-link {
  display: block;
  text-align: center;
  font-size: 0.92rem;
  font-weight: 700;
  color: #0064ff;
  text-decoration: none;
  transition: opacity 0.15s;
}
.register-link:hover {
  opacity: 0.8;
}

/* ─── Responsive ─── */
@media (max-width: 768px) {
  .login-page { flex-direction: column; }
  .login-brand { flex: 0 0 auto; min-height: 240px; padding: 2rem; }
  .brand-title { font-size: 1.8rem; }
  .brand-features { display: none; }
}
</style>
