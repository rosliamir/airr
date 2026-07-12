import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { adminMenu } from '../config/admin-menu'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import ForgotPasswordView from '../views/ForgotPasswordView.vue'
import ResetPasswordView from '../views/ResetPasswordView.vue'
import DashboardView from '../views/DashboardView.vue'
import AuditView from '../views/AuditView.vue'
import UsersView from '../views/UsersView.vue'
import ProjectsView from '../views/ProjectsView.vue'
import SettingsView from '../views/SettingsView.vue'
import DataSourcesView from '../views/DataSourcesView.vue'
import KnowledgeBaseView from '../views/KnowledgeBaseView.vue'
import ReportsView from '../views/ReportsView.vue'
import MenusView from '../views/MenusView.vue'
import AiOrchestrationView from '../views/AiOrchestrationView.vue'
import TemplatesView from '../views/TemplatesView.vue'
import ReportViewerView from '../views/ReportViewerView.vue'
import ComingSoonView from '../views/ComingSoonView.vue'

// Real views for the modules that are already built.
const readyViews: Record<string, () => unknown> = {
  dashboard: () => DashboardView,
  audit: () => AuditView,
  users: () => UsersView,
  projects: () => ProjectsView,
  settings: () => SettingsView,
  datasources: () => DataSourcesView,
  'knowledge-base': () => KnowledgeBaseView,
  reports: () => ReportsView,
  menus: () => MenusView,
  'ai-orchestration': () => AiOrchestrationView,
  templates: () => TemplatesView,
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
    { path: '/register', name: 'register', component: RegisterView, meta: { guestOnly: true, title: 'Register' } },
    { path: '/forgot-password', name: 'forgot-password', component: ForgotPasswordView, meta: { guestOnly: true, title: 'Forgot password' } },
    { path: '/reset-password', name: 'reset-password', component: ResetPasswordView, meta: { guestOnly: true, title: 'Reset password' } },
    { path: '/reports/:id/view', name: 'report-view', component: ReportViewerView, meta: { requiresAuth: true, title: 'Report', permission: 'reports.run' } },
    { path: '/views/:savedViewId', name: 'saved-view', component: ReportViewerView, meta: { requiresAuth: true, title: 'Report', permission: 'reports.run' } },
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
