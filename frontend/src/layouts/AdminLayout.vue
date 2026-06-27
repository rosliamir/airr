<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import * as icons from 'lucide-vue-next'
import { adminMenu, type MenuItem } from '../config/admin-menu'
import { apiRequest } from '../api/client'
import { useAuthStore } from '../stores/auth'
import AirrLogo from '../components/AirrLogo.vue'
import UserAvatar from '../components/UserAvatar.vue'

const auth = useAuthStore()
const router = useRouter()

function iconFor(name: string | undefined) {
  return (name && (icons as Record<string, unknown>)[name]) || icons.Circle
}

type NavItem = { label: string; route: string | null; icon?: string }
type NavGroup = { title: string | null; items: NavItem[] }

// A static-config item is visible when the user holds its permission AND feature.
function visible(item: MenuItem): boolean {
  if (item.permission && !auth.can(item.permission)) return false
  if (item.feature && !auth.hasFeature(item.feature)) return false
  return true
}

const staticGroups = computed<NavGroup[]>(() =>
  adminMenu
    .map((g) => ({ title: g.title, items: g.items.filter(visible) as NavItem[] }))
    .filter((g) => g.items.length > 0),
)

// DB-driven nav (M14). Falls back to the static config if unavailable.
const apiGroups = ref<NavGroup[] | null>(null)
const groups = computed<NavGroup[]>(() => apiGroups.value ?? staticGroups.value)

type NavNode = { label: string; route: string | null; icon?: string; children?: NavNode[] }
onMounted(async () => {
  try {
    const tree = (await apiRequest<{ data: NavNode[] }>('/menus/nav')).data
    if (tree.length) {
      apiGroups.value = tree.map((n) =>
        n.route
          ? { title: null, items: [{ label: n.label, route: n.route, icon: n.icon }] }
          : { title: n.label, items: (n.children ?? []).map((c) => ({ label: c.label, route: c.route, icon: c.icon })) },
      ).filter((g) => g.items.length > 0)
    }
  } catch { /* keep static fallback */ }
})

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
            :key="item.route ?? item.label"
            :to="{ name: item.route ?? 'dashboard' }"
            class="group flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100 transition"
            active-class="bg-airr-50 text-airr-700 font-medium"
          >
            <component :is="iconFor(item.icon)" :size="17" class="shrink-0" />
            <span class="flex-1">{{ item.label }}</span>
          </RouterLink>
        </div>
      </nav>

      <div class="p-3 border-t border-slate-100">
        <div class="flex items-center gap-2 px-2 py-1.5">
          <UserAvatar :name="auth.user?.name ?? '?'" :src="auth.user?.avatar_url" :size="32" />
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
