<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { Eye, EyeOff } from 'lucide-vue-next'
import { useAuthStore } from '../stores/auth'
import { ApiException } from '../api/client'
import AirrLogo from '../components/AirrLogo.vue'
import MalaysiaBackdrop from '../components/MalaysiaBackdrop.vue'

const auth = useAuthStore()
const router = useRouter()
const email = ref('admin@airr.technology')
const password = ref('airr12345')
const showPassword = ref(false)
const error = ref('')
const info = ref('')

async function submit() {
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Login failed'
  }
}

function googleSignIn() {
  // Browser redirect into the backend OAuth flow (not XHR).
  window.location.href = '/api/auth/google/redirect'
}

// Handle a token handoff back into the SPA — Google OAuth or KERISI SSO
// (?token=…&redirect=…, or ?error=…). `redirect` must be an internal path.
onMounted(async () => {
  const params = new URLSearchParams(window.location.search)
  const token = params.get('token')
  if (token) {
    try {
      await auth.adoptToken(token)
      const redirect = params.get('redirect')
      if (redirect && redirect.startsWith('/') && !redirect.includes('://')) {
        router.replace(redirect)
      } else {
        router.replace({ name: 'dashboard' })
      }
      return
    } catch {
      error.value = 'Sign-in failed. Please try again.'
    }
  }
  if (params.get('error')?.startsWith('sso')) {
    error.value = 'KERISI sign-in failed. Please try again or log in with your AIRR credentials.'
  } else if (params.get('error') === 'google') {
    error.value = 'Google sign-in failed. Please try again.'
  }
  // Strip query so a refresh doesn't replay the redirect params.
  if (token || params.has('error')) {
    window.history.replaceState({}, '', window.location.pathname)
  }
})

const year = 2026
</script>

<template>
  <div class="min-h-screen flex flex-col items-center justify-center px-4 relative">
    <MalaysiaBackdrop />

    <div class="w-full max-w-sm bg-white/95 backdrop-blur rounded-2xl shadow-xl border border-white/60 p-8">
      <div class="flex flex-col items-center mb-6">
        <AirrLogo :size="52" />
        <h1 class="mt-3 text-2xl font-bold tracking-tight">AIRR</h1>
        <p class="text-xs text-slate-400 uppercase tracking-widest">AI &amp; RAG Reporting</p>
        <p class="mt-1 text-sm text-slate-500 italic">Thin &amp; light, but powerful.</p>
      </div>

      <!-- Google / Gmail -->
      <button type="button" @click="googleSignIn"
        class="w-full flex items-center justify-center gap-2.5 rounded-lg border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50 transition">
        <svg width="18" height="18" viewBox="0 0 48 48">
          <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.6 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"/>
          <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
          <path fill="#4CAF50" d="M24 44c5.2 0 10-2 13.6-5.2l-6.3-5.3C29.2 35.1 26.7 36 24 36c-5.3 0-9.7-3.4-11.3-8.1l-6.5 5C9.6 39.6 16.2 44 24 44z"/>
          <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.1-4 5.5l6.3 5.3C41.6 35.7 44 30.3 44 24c0-1.3-.1-2.3-.4-3.5z"/>
        </svg>
        Continue with Google
      </button>

      <div class="flex items-center gap-3 my-5">
        <span class="h-px flex-1 bg-slate-200"></span>
        <span class="text-xs text-slate-400">or</span>
        <span class="h-px flex-1 bg-slate-200"></span>
      </div>

      <form @submit.prevent="submit" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Email</label>
          <input v-model="email" type="email" required autocomplete="username"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 focus:border-airr-500 outline-none" />
        </div>
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="block text-sm font-medium text-slate-600">Password</label>
            <RouterLink :to="{ name: 'forgot-password' }" class="text-xs text-airr-600 hover:underline">Forgot password?</RouterLink>
          </div>
          <div class="relative">
            <input v-model="password" :type="showPassword ? 'text' : 'password'" required autocomplete="current-password"
              class="w-full rounded-lg border border-slate-200 px-3 py-2 pr-10 focus:ring-2 focus:ring-airr-300 focus:border-airr-500 outline-none" />
            <button type="button" @click="showPassword = !showPassword" :title="showPassword ? 'Hide' : 'Show'"
              class="absolute inset-y-0 right-2 flex items-center text-slate-400 hover:text-slate-600">
              <component :is="showPassword ? EyeOff : Eye" :size="18" />
            </button>
          </div>
        </div>

        <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
        <p v-if="info" class="text-sm text-slate-600 bg-slate-100 rounded-lg px-3 py-2">{{ info }}</p>

        <button type="submit" :disabled="auth.loading"
          class="w-full rounded-lg py-2.5 font-semibold text-white bg-gradient-to-r from-airr-300 via-airr-500 to-airr-700 hover:opacity-95 disabled:opacity-60 transition">
          {{ auth.loading ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>

      <p class="mt-5 text-center text-sm text-slate-500">
        Don't have an account?
        <RouterLink :to="{ name: 'register' }" class="text-airr-600 font-medium hover:underline">Register</RouterLink>
      </p>
    </div>

    <!-- Copyright -->
    <footer class="mt-6 text-center text-xs text-slate-400">
      © {{ year }} AIRR — AI &amp; RAG Reporting · airr.technology
    </footer>
  </div>
</template>
