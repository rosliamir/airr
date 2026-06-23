<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import AirrLogo from '../components/AirrLogo.vue'
import MalaysiaBackdrop from '../components/MalaysiaBackdrop.vue'

const auth = useAuthStore()
const email = ref('')
const done = ref(false)

async function submit() {
  // POST /api/auth/forgot-password — sends a reset link via the Laravel
  // password broker. Response is always generic (no account enumeration).
  try {
    await auth.forgotPassword(email.value)
  } finally {
    done.value = true
  }
}

const year = 2026
</script>

<template>
  <div class="min-h-screen flex flex-col items-center justify-center px-4 relative">
    <MalaysiaBackdrop />

    <div class="w-full max-w-sm bg-white/95 backdrop-blur rounded-2xl shadow-xl border border-white/60 p-8">
      <div class="flex flex-col items-center mb-6">
        <AirrLogo :size="48" />
        <h1 class="mt-3 text-xl font-bold">Forgot password</h1>
        <p class="mt-1 text-sm text-slate-500 text-center">Enter your email and we'll send a reset link.</p>
      </div>

      <div v-if="done" class="text-center space-y-3">
        <div class="text-emerald-600 text-4xl">✓</div>
        <p class="text-slate-600">If the email exists, a reset link has been sent.</p>
        <RouterLink :to="{ name: 'login' }" class="inline-block mt-2 text-airr-600 font-medium hover:underline">
          Back to sign in
        </RouterLink>
      </div>

      <form v-else @submit.prevent="submit" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Email</label>
          <input v-model="email" type="email" required class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <button type="submit"
          class="w-full rounded-lg py-2.5 font-semibold text-white bg-gradient-to-r from-airr-300 via-airr-500 to-airr-700 hover:opacity-95 transition">
          Send reset link
        </button>
        <p class="text-center text-sm text-slate-500">
          <RouterLink :to="{ name: 'login' }" class="text-airr-600 hover:underline">Back to sign in</RouterLink>
        </p>
      </form>
    </div>

    <footer class="mt-6 text-center text-xs text-slate-400">
      © {{ year }} AIRR — AI &amp; RAG Reporting · airr.technology
    </footer>
  </div>
</template>
