<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import * as icons from 'lucide-vue-next'
import { adminMenu, type MenuItem } from '../config/admin-menu'
import { apiRequest } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { THEMES, theme, applyTheme, type Theme } from '../composables/theme'
import AirrLogo from '../components/AirrLogo.vue'
import UserAvatar from '../components/UserAvatar.vue'
import ProjectAvatar from '../components/ProjectAvatar.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

// Sidebar collapse (icon-only). Persisted; some menu items auto-collapse (authoring).
const collapsed = ref(false)
try { collapsed.value = localStorage.getItem('airr-sidebar-collapsed') === '1' } catch { /* ignore */ }
function toggleCollapsed() {
  collapsed.value = !collapsed.value
  try { localStorage.setItem('airr-sidebar-collapsed', collapsed.value ? '1' : '0') } catch { /* ignore */ }
}
const autoCollapseRoutes = ref<Set<string>>(new Set())
// Auto-minimise when navigating to an authoring item flagged auto_collapse.
watch(() => route.name, (name) => {
  if (name && autoCollapseRoutes.value.has(String(name))) collapsed.value = true
})

// User dropdown (top-right) + current-project switch + theme.
const menuOpen = ref(false)
const switching = ref(false)
const projectSearch = ref('')

const allProjects = computed(() => auth.user?.projects ?? [])
const filteredProjects = computed(() => {
  const q = projectSearch.value.trim().toLowerCase()
  if (!q) return allProjects.value
  return allProjects.value.filter((p) => p.name.toLowerCase().includes(q) || p.code.toLowerCase().includes(q))
})
const shortName = (s: string) => s.slice(0, 5)

async function switchProject(id: number | null) {
  if (id === (auth.user?.current_project?.id ?? null)) { menuOpen.value = false; return }
  switching.value = true
  try { await auth.setCurrentProject(id) } finally { switching.value = false; menuOpen.value = false }
}
function chooseTheme(t: Theme) { applyTheme(t) }

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

type NavNode = { label: string; route: string | null; icon?: string; auto_collapse?: boolean; children?: NavNode[] }
onMounted(async () => {
  try {
    const tree = (await apiRequest<{ data: NavNode[] }>('/menus/nav')).data
    if (tree.length) {
      const collapseSet = new Set<string>()
      const note = (n: NavNode) => { if (n.route && n.auto_collapse) collapseSet.add(n.route) }
      tree.forEach((n) => { note(n); (n.children ?? []).forEach(note) })
      autoCollapseRoutes.value = collapseSet
      // Apply immediately if we loaded straight onto an auto-collapse route.
      if (route.name && collapseSet.has(String(route.name))) collapsed.value = true
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
    <aside class="shrink-0 bg-white border-r border-slate-200 flex flex-col transition-all duration-200" :class="collapsed ? 'w-16' : 'w-64'">
      <div class="h-16 flex items-center border-b border-slate-100" :class="collapsed ? 'justify-center px-0' : 'justify-between pl-5 pr-2'">
        <div class="flex items-center gap-2.5">
          <AirrLogo :size="30" />
          <div v-if="!collapsed">
            <div class="font-bold leading-none">AIRR</div>
            <div class="text-[9px] text-slate-400 uppercase tracking-widest">Studio</div>
          </div>
        </div>
        <button v-if="!collapsed" @click="toggleCollapsed" title="Collapse"
          class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100">
          <component :is="icons.PanelLeftClose" :size="18" />
        </button>
      </div>
      <!-- Expand button (when collapsed) -->
      <button v-if="collapsed" @click="toggleCollapsed" title="Expand"
        class="h-9 flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-50 border-b border-slate-100">
        <component :is="icons.PanelLeftOpen" :size="18" />
      </button>

      <nav class="flex-1 overflow-y-auto py-3 px-2 space-y-4">
        <div v-for="(group, gi) in groups" :key="gi">
          <p v-if="group.title && !collapsed" class="px-3 mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">
            {{ group.title }}
          </p>
          <div v-else-if="group.title && collapsed" class="border-t border-slate-100 my-2 mx-2"></div>
          <RouterLink
            v-for="item in group.items"
            :key="item.route ?? item.label"
            :to="{ name: item.route ?? 'dashboard' }"
            :title="collapsed ? item.label : undefined"
            class="group flex items-center rounded-lg text-sm text-slate-600 hover:bg-slate-100 transition"
            :class="collapsed ? 'justify-center px-2 py-2.5' : 'gap-2.5 px-3 py-2'"
            active-class="bg-airr-50 text-airr-700 font-medium"
          >
            <component :is="iconFor(item.icon)" :size="17" class="shrink-0" />
            <span v-if="!collapsed" class="flex-1">{{ item.label }}</span>
          </RouterLink>
        </div>
      </nav>

    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0">
      <header class="relative z-10 h-16 bg-white border-b border-slate-200 flex items-center justify-between px-6">
        <slot name="header">
          <h1 class="text-lg font-semibold">{{ $route.meta.title }}</h1>
        </slot>

        <div class="flex items-center gap-3">
          <!-- Current project chip (colored avatar + short name) -->
          <div v-if="auth.user?.current_project" class="hidden sm:flex items-center gap-2 text-sm">
            <ProjectAvatar :name="auth.user.current_project.name" :code="auth.user.current_project.code" :color="auth.user.current_project.color" :size="26" />
            <div class="leading-tight">
              <div class="font-semibold text-slate-700">{{ auth.user.current_project.name }}</div>
              <div class="text-[10px] text-slate-400">{{ auth.user.current_project.code }} · {{ auth.user.current_project.reports_count }} reports</div>
            </div>
          </div>

          <!-- Notifications (placeholder) -->
          <button class="relative text-slate-400 hover:text-slate-600" title="Notifications">
            <component :is="icons.Bell" :size="19" />
            <span class="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-airr-500"></span>
          </button>

          <!-- User dropdown -->
          <div class="relative">
            <button @click="menuOpen = !menuOpen" class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-lg hover:bg-slate-100">
              <UserAvatar :name="auth.user?.name ?? '?'" :src="auth.user?.avatar_url" :size="30" />
              <span class="text-sm font-medium text-slate-700 hidden sm:block">{{ auth.user?.name }}</span>
              <component :is="icons.ChevronDown" :size="15" class="text-slate-400" />
            </button>

            <!-- backdrop -->
            <div v-if="menuOpen" class="fixed inset-0 z-[90]" @click="menuOpen = false"></div>

            <div v-if="menuOpen" class="absolute right-0 mt-2 w-72 bg-white rounded-xl border border-slate-200 shadow-xl z-[100] overflow-hidden">
              <!-- Identity + edition -->
              <div class="p-4 border-b border-slate-100">
                <div class="font-semibold text-slate-800">{{ auth.user?.name }}</div>
                <div class="text-xs text-slate-400">{{ auth.user?.email }}</div>
                <span class="inline-block mt-2 text-[10px] px-2 py-0.5 rounded-full bg-airr-50 text-airr-700 font-medium uppercase">
                  {{ auth.user?.edition }} edition
                </span>
              </div>

              <!-- Current project switcher -->
              <div class="p-4 border-b border-slate-100">
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400 mb-1.5">Current project</label>

                <!-- Always searchable; scrolls when projects grow -->
                <input v-if="allProjects.length > 6" v-model="projectSearch" type="search" placeholder="Search projects…"
                  class="w-full mb-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
                <div class="grid grid-cols-2 gap-1.5 max-h-56 overflow-y-auto pr-0.5">
                  <button v-for="p in filteredProjects" :key="p.id" @click="switchProject(p.id)" :disabled="switching"
                    class="flex items-center gap-2 px-2 py-1.5 rounded-lg border text-left transition"
                    :class="p.id === auth.user?.current_project?.id ? 'border-airr-300 bg-airr-50' : 'border-slate-100 hover:bg-slate-50'">
                    <ProjectAvatar :name="p.name" :code="p.code" :color="p.color" :size="24" />
                    <span class="min-w-0">
                      <span class="block text-xs font-medium text-slate-700 truncate" :title="p.name">{{ shortName(p.name) }}</span>
                      <span class="block text-[10px] text-slate-400">{{ p.reports_count }} rpt</span>
                    </span>
                  </button>
                  <p v-if="!filteredProjects.length" class="col-span-2 text-xs text-slate-400 px-1 py-1">{{ allProjects.length ? 'No match.' : 'No projects assigned.' }}</p>
                </div>
                <p class="text-[11px] text-slate-400 mt-2">Default scope for authoring. Set here.</p>
              </div>

              <!-- Theme -->
              <div class="p-4 border-b border-slate-100">
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400 mb-1.5">Theme</label>
                <div class="space-y-1">
                  <button v-for="t in THEMES" :key="t.value" @click="chooseTheme(t.value)"
                    class="w-full flex items-center justify-between px-2.5 py-1.5 rounded-lg text-sm hover:bg-slate-50"
                    :class="theme === t.value ? 'bg-airr-50 text-airr-700 font-medium' : 'text-slate-600'">
                    <span>{{ t.label }} <span class="text-[10px] text-slate-400">· {{ t.hint }}</span></span>
                    <component v-if="theme === t.value" :is="icons.Check" :size="15" />
                  </button>
                </div>
              </div>

              <!-- Sign out -->
              <button @click="logout" class="w-full flex items-center gap-2 px-4 py-3 text-sm text-slate-600 hover:bg-slate-50">
                <component :is="icons.LogOut" :size="16" /> Sign out
              </button>
            </div>
          </div>
        </div>
      </header>
      <main class="flex-1 overflow-y-auto p-6">
        <slot />
      </main>
    </div>
  </div>
</template>
