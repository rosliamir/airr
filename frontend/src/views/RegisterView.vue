<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ApiException } from '../api/client'
import AirrLogo from '../components/AirrLogo.vue'
import MalaysiaBackdrop from '../components/MalaysiaBackdrop.vue'

const auth = useAuthStore()
const name = ref('')
const email = ref('')
const password = ref('')
const done = ref(false)
const error = ref('')

async function submit() {
  // POST /api/auth/register — creates a PENDING account that needs
  // project-admin approval before access.
  error.value = ''
  try {
    await auth.register(name.value, email.value, password.value)
    done.value = true
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Registration failed'
  }
}

function googleRegister() {
  // Browser redirect into the backend OAuth flow (not XHR).
  window.location.href = '/api/auth/google/redirect'
}

const year = 2026
</script>

<template>
  <div class="min-h-screen flex flex-col items-center justify-center px-4 relative">
    <MalaysiaBackdrop />

    <div class="w-full max-w-sm bg-white/95 backdrop-blur rounded-2xl shadow-xl border border-white/60 p-8">
      <div class="flex flex-col items-center mb-6">
        <AirrLogo :size="48" />
        <h1 class="mt-3 text-xl font-bold">Create your AIRR account</h1>
      </div>

      <div v-if="done" class="text-center space-y-3">
        <div class="text-emerald-600 text-4xl">✓</div>
        <p class="text-slate-600">Request received.</p>
        <p class="text-sm text-slate-500">
          Your account is <span class="font-medium">pending project-admin approval</span>.
          You'll get access once approved and assigned to a project.
        </p>
        <RouterLink :to="{ name: 'login' }" class="inline-block mt-2 text-airr-600 font-medium hover:underline">
          Back to sign in
        </RouterLink>
      </div>

      <template v-else>
        <button type="button" @click="googleRegister"
          class="w-full flex items-center justify-center gap-2.5 rounded-lg border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50 transition mb-5">
          <svg width="18" height="18" viewBox="0 0 48 48">
            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.6 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"/>
            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
            <path fill="#4CAF50" d="M24 44c5.2 0 10-2 13.6-5.2l-6.3-5.3C29.2 35.1 26.7 36 24 36c-5.3 0-9.7-3.4-11.3-8.1l-6.5 5C9.6 39.6 16.2 44 24 44z"/>
            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.1-4 5.5l6.3 5.3C41.6 35.7 44 30.3 44 24c0-1.3-.1-2.3-.4-3.5z"/>
          </svg>
          Register with Google
        </button>

        <form @submit.prevent="submit" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
            <input v-model="name" required class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Email</label>
            <input v-model="email" type="email" required class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Password</label>
            <input v-model="password" type="password" required class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>

          <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

          <p class="text-xs text-slate-400 bg-airr-50 rounded-lg px-3 py-2">
            ℹ️ Registration requires <span class="font-medium text-airr-700">project-admin approval</span> before access is granted.
          </p>

          <button type="submit" :disabled="auth.loading"
            class="w-full rounded-lg py-2.5 font-semibold text-white bg-gradient-to-r from-airr-300 via-airr-500 to-airr-700 hover:opacity-95 disabled:opacity-60 transition">
            {{ auth.loading ? 'Submitting…' : 'Register' }}
          </button>
        </form>

        <p class="mt-5 text-center text-sm text-slate-500">
          Already have an account?
          <RouterLink :to="{ name: 'login' }" class="text-airr-600 font-medium hover:underline">Sign in</RouterLink>
        </p>
      </template>
    </div>

    <footer class="mt-6 text-center text-xs text-slate-400">
      © {{ year }} AIRR — AI &amp; RAG Reporting · airr.technology
    </footer>
  </div>
</template>
