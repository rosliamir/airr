<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ApiException } from '../api/client'
import AirrLogo from '../components/AirrLogo.vue'

const auth = useAuthStore()
const router = useRouter()
const email = ref('admin@airr.technology')
const password = ref('airr12345')
const error = ref('')

async function submit() {
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Login failed'
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-xl border border-slate-100 p-8">
      <div class="flex flex-col items-center mb-6">
        <AirrLogo :size="52" />
        <h1 class="mt-3 text-2xl font-bold tracking-tight">AIRR</h1>
        <p class="text-xs text-slate-400 uppercase tracking-widest">AI &amp; RAG Reporting</p>
        <p class="mt-1 text-sm text-slate-500 italic">Thin &amp; light, but powerful.</p>
      </div>

      <form @submit.prevent="submit" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Email</label>
          <input v-model="email" type="email" required
            class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 focus:border-airr-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Password</label>
          <input v-model="password" type="password" required
            class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 focus:border-airr-500 outline-none" />
        </div>

        <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

        <button type="submit" :disabled="auth.loading"
          class="w-full rounded-lg py-2.5 font-semibold text-white bg-gradient-to-r from-airr-300 via-airr-500 to-airr-700 hover:opacity-95 disabled:opacity-60 transition">
          {{ auth.loading ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>
    </div>
  </div>
</template>
