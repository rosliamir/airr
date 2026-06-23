<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ApiException } from '../api/client'
import AirrLogo from '../components/AirrLogo.vue'
import MalaysiaBackdrop from '../components/MalaysiaBackdrop.vue'

const auth = useAuthStore()
const route = useRoute()

const token = ref('')
const email = ref('')
const password = ref('')
const passwordConfirm = ref('')
const done = ref(false)
const error = ref('')

onMounted(() => {
  // Token + email arrive as query params from the reset e-mail link.
  token.value = (route.query.token as string) ?? ''
  email.value = (route.query.email as string) ?? ''
})

async function submit() {
  error.value = ''
  if (password.value !== passwordConfirm.value) {
    error.value = 'Passwords do not match.'
    return
  }
  try {
    await auth.resetPassword({
      token: token.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirm.value,
    })
    done.value = true
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Password reset failed'
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
        <h1 class="mt-3 text-xl font-bold">Reset password</h1>
      </div>

      <div v-if="done" class="text-center space-y-3">
        <div class="text-emerald-600 text-4xl">✓</div>
        <p class="text-slate-600">Your password has been changed.</p>
        <RouterLink :to="{ name: 'login' }" class="inline-block mt-2 text-airr-600 font-medium hover:underline">
          Sign in
        </RouterLink>
      </div>

      <form v-else @submit.prevent="submit" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Email</label>
          <input v-model="email" type="email" required autocomplete="username"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">New password</label>
          <input v-model="password" type="password" required autocomplete="new-password"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Confirm password</label>
          <input v-model="passwordConfirm" type="password" required autocomplete="new-password"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>

        <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

        <button type="submit" :disabled="auth.loading"
          class="w-full rounded-lg py-2.5 font-semibold text-white bg-gradient-to-r from-airr-300 via-airr-500 to-airr-700 hover:opacity-95 disabled:opacity-60 transition">
          {{ auth.loading ? 'Saving…' : 'Reset password' }}
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
