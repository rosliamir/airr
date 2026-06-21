import { defineStore } from 'pinia'
import { apiRequest, setToken } from '../api/client'

export type AuthUser = {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
  clearance: number
  edition: string
  features: string[]
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
    async fetchMe() {
      try {
        const res = await apiRequest<{ data: AuthUser }>('/auth/me')
        this.user = res.data
      } catch {
        this.user = null
        setToken(null)
      }
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
