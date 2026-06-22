import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { adminMenu } from '../config/admin-menu'
import LoginView from '../views/LoginView.vue'
import DashboardView from '../views/DashboardView.vue'
import AuditView from '../views/AuditView.vue'
import ComingSoonView from '../views/ComingSoonView.vue'

// Real views for the modules that are already built.
const readyViews: Record<string, () => unknown> = {
  dashboard: () => DashboardView,
  audit: () => AuditView,
}

// Build protected routes from the menu config so the two never drift.
const menuRoutes: RouteRecordRaw[] = adminMenu
  .flatMap((g) => g.items)
  .map((item) => ({
    path: `/${item.route === 'dashboard' ? 'dashboard' : item.route}`,
    name: item.route,
    component: (readyViews[item.route]?.() as never) ?? ComingSoonView,
    meta: {
      requiresAuth: true,
      title: item.label,
      module: item.module,
      phase: item.phase,
      permission: item.permission,
      feature: item.feature,
    },
  }))

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/dashboard' },
    { path: '/login', name: 'login', component: LoginView, meta: { guestOnly: true, title: 'Sign in' } },
    ...menuRoutes,
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.user && localStorage.getItem('airr_token')) {
    await auth.fetchMe()
  }
  if (to.meta.requiresAuth && !auth.isAuthenticated) return { name: 'login' }
  if (to.meta.guestOnly && auth.isAuthenticated) return { name: 'dashboard' }

  // Block direct navigation to a route the user lacks permission/feature for.
  const perm = to.meta.permission as string | undefined
  const feat = to.meta.feature as string | undefined
  if (perm && !auth.can(perm)) return { name: 'dashboard' }
  if (feat && !auth.hasFeature(feat)) return { name: 'dashboard' }
})

router.afterEach((to) => {
  document.title = `AIRR · ${(to.meta.title as string) ?? ''}`.trim()
})

export default router
