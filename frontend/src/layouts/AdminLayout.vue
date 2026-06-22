<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import * as icons from 'lucide-vue-next'
import { adminMenu, type MenuItem } from '../config/admin-menu'
import { useAuthStore } from '../stores/auth'
import AirrLogo from '../components/AirrLogo.vue'

const auth = useAuthStore()
const router = useRouter()

function iconFor(name: string) {
  return (icons as Record<string, unknown>)[name] ?? icons.Circle
}

// A menu item is visible when the user holds its permission AND its edition feature.
function visible(item: MenuItem): boolean {
  if (item.permission && !auth.can(item.permission)) return false
  if (item.feature && !auth.hasFeature(item.feature)) return false
  return true
}

const groups = computed(() =>
  adminMenu
    .map((g) => ({ ...g, items: g.items.filter(visible) }))
    .filter((g) => g.items.length > 0),
)

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen flex bg-slate-50">
    <!-- Sidebar -->
    <aside class="w-64 shrink-0 bg-white border-r border-slate-200 flex flex-col">
      <div class="h-16 flex items-center gap-2.5 px-5 border-b border-slate-100">
        <AirrLogo :size="30" />
        <div>
          <div class="font-bold leading-none">AIRR</div>
          <div class="text-[9px] text-slate-400 uppercase tracking-widest">Studio</div>
        </div>
      </div>

      <nav class="flex-1 overflow-y-auto py-3 px-2 space-y-4">
        <div v-for="(group, gi) in groups" :key="gi">
          <p v-if="group.title" class="px-3 mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">
            {{ group.title }}
          </p>
          <RouterLink
            v-for="item in group.items"
            :key="item.route"
            :to="{ name: item.route }"
            class="group flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100 transition"
            active-class="bg-airr-50 text-airr-700 font-medium"
          >
            <component :is="iconFor(item.icon)" :size="17" class="shrink-0" />
            <span class="flex-1">{{ item.label }}</span>
            <span v-if="!item.ready"
              class="text-[8px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-400 uppercase">P{{ item.phase }}</span>
          </RouterLink>
        </div>
      </nav>

      <div class="p-3 border-t border-slate-100">
        <div class="flex items-center gap-2 px-2 py-1.5">
          <div class="w-8 h-8 rounded-full bg-gradient-to-br from-airr-300 to-airr-700 text-white flex items-center justify-center text-xs font-bold">
            {{ auth.user?.name?.charAt(0) ?? '?' }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium truncate">{{ auth.user?.name }}</div>
            <div class="text-[11px] text-slate-400 truncate">{{ auth.user?.roles.join(', ') }}</div>
          </div>
          <button @click="logout" :title="'Sign out'" class="text-slate-400 hover:text-airr-600">
            <component :is="icons.LogOut" :size="16" />
          </button>
        </div>
      </div>
    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0">
      <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-6">
        <slot name="header">
          <h1 class="text-lg font-semibold">{{ $route.meta.title }}</h1>
        </slot>
        <span class="text-xs px-2.5 py-1 rounded-full bg-airr-50 text-airr-700 font-medium uppercase">
          {{ auth.user?.edition }} edition
        </span>
      </header>
      <main class="flex-1 overflow-y-auto p-6">
        <slot />
      </main>
    </div>
  </div>
</template>
