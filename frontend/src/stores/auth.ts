import { defineStore } from 'pinia'
import { apiRequest, setToken } from '../api/client'

export type ProjectRef = { id: number; code: string; name: string }
export type AuthUser = {
  id: number
  name: string
  email: string
  avatar_url: string | null
  user_type: string
  roles: string[]
  permissions: string[]
  clearance: number
  edition: string
  features: string[]
  current_project: ProjectRef | null
  projects: ProjectRef[]
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as AuthUser | null,
    loading: false,
  }),
  getters: {
    isAuthenticated: (s) => !!s.user,
    can: (s) => (perm: string) => s.user?.permissions.includes(perm) ?? false,
    hasFeature: (s) => (f: string) => s.user?.features.includes(f) ?? false,
  },
  actions: {
    async login(email: string, password: string) {
      this.loading = true
      try {
        const res = await apiRequest<{ data: { token: string; user: AuthUser } }>('/auth/login', {
          method: 'POST',
          body: JSON.stringify({ email, password }),
        })
        setToken(res.data.token)
        this.user = res.data.user
      } finally {
        this.loading = false
      }
    },
    // Self-registration — returns a PENDING account awaiting admin approval.
    async register(name: string, email: string, password: string) {
      this.loading = true
      try {
        await apiRequest('/auth/register', {
          method: 'POST',
          body: JSON.stringify({ name, email, password }),
        })
      } finally {
        this.loading = false
      }
    },
    async forgotPassword(email: string) {
      this.loading = true
      try {
        await apiRequest('/auth/forgot-password', {
          method: 'POST',
          body: JSON.stringify({ email }),
        })
      } finally {
        this.loading = false
      }
    },
    async resetPassword(payload: { token: string; email: string; password: string; password_confirmation: string }) {
      this.loading = true
      try {
        await apiRequest('/auth/reset-password', {
          method: 'POST',
          body: JSON.stringify(payload),
        })
      } finally {
        this.loading = false
      }
    },
    // Adopt a token handed back by the Google OAuth redirect (?token=…).
    async adoptToken(token: string) {
      setToken(token)
      await this.fetchMe()
    },
    async fetchMe() {
      try {
        const res = await apiRequest<{ data: AuthUser }>('/auth/me')
        this.user = res.data
      } catch {
        this.user = null
        setToken(null)
      }
    },
    // FR-M14 — switch the current project (default authoring scope).
    async setCurrentProject(projectId: number | null) {
      const res = await apiRequest<{ data: AuthUser }>('/me/current-project', {
        method: 'PUT',
        body: JSON.stringify({ project_id: projectId }),
      })
      this.user = res.data
    },
    async logout() {
      try {
        await apiRequest('/auth/logout', { method: 'POST' })
      } finally {
        this.user = null
        setToken(null)
      }
    },
  },
})
