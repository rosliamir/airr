<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { Eye, EyeOff, MailCheck } from 'lucide-vue-next'
import { apiRequest, ApiException, uploadFile } from '../../api/client'
import UserAvatar from '../UserAvatar.vue'

const showPassword = ref(false)
// Basic email validity for inline feedback (test-email action is a placeholder).
const emailValid = computed(() => !form.value.email || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email))
function testEmail() {
  // TODO: wire to a backend "verify email deliverability" endpoint.
  alert(emailValid.value ? 'Email format looks valid. (Delivery test not implemented yet.)' : 'Invalid email format.')
}

type Role = { id: number; slug: string; name: string }
type UserRow = {
  id: number
  name: string
  email: string
  status: 'pending' | 'active' | 'suspended'
  user_type: 'system_admin' | 'admin' | 'user'
  auth_provider: string
  avatar_url: string | null
  roles: Role[]
  clearance: number
}

const users = ref<UserRow[]>([])
const roles = ref<Role[]>([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const filter = ref<'all' | 'pending' | 'active' | 'suspended'>('all')

const USER_TYPES = [
  { value: 'system_admin', label: 'System Admin' },
  { value: 'admin', label: 'Admin' },
  { value: 'user', label: 'User' },
]
const typeLabel = (v: string) => USER_TYPES.find((t) => t.value === v)?.label ?? v

const filtered = computed(() => (filter.value === 'all' ? users.value : users.value.filter((u) => u.status === filter.value)))
const pendingCount = computed(() => users.value.filter((u) => u.status === 'pending').length)

const showForm = ref(false)
const editing = ref<UserRow | null>(null)
const form = ref({
  name: '', email: '', password: '', user_type: 'user',
  status: 'active', roles: [] as number[],
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [u, r] = await Promise.all([
      apiRequest<{ data: UserRow[] }>('/users?limit=100'),
      apiRequest<{ data: Role[] }>('/roles'),
    ])
    users.value = u.data
    roles.value = r.data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load users'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = { name: '', email: '', password: '', user_type: 'user', status: 'active', roles: [] }
  showForm.value = true
}
function openEdit(u: UserRow) {
  editing.value = u
  form.value = {
    name: u.name, email: u.email, password: '', user_type: u.user_type,
    status: u.status, roles: u.roles.map((r) => r.id),
  }
  showForm.value = true
}
function toggleRole(id: number) {
  form.value.roles = form.value.roles.includes(id)
    ? form.value.roles.filter((x) => x !== id)
    : [...form.value.roles, id]
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const body: Record<string, unknown> = {
      name: form.value.name, email: form.value.email, user_type: form.value.user_type,
      status: form.value.status, roles: form.value.roles,
    }
    if (form.value.password) body.password = form.value.password
    const path = editing.value ? `/users/${editing.value.id}` : '/users'
    await apiRequest(path, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(body) })
    showForm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

async function onAvatarPick(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file || !editing.value) return
  busy.value = true
  error.value = ''
  try {
    const form = new FormData()
    form.append('avatar', file)
    const res = await uploadFile<{ data: UserRow }>(`/users/${editing.value.id}/avatar`, form)
    editing.value = res.data // refresh preview
    await load()
  } catch (err) {
    error.value = err instanceof ApiException ? err.error.message : 'Avatar upload failed'
  } finally {
    busy.value = false
  }
}

async function act(u: UserRow, path: string, method = 'POST') {
  busy.value = true
  error.value = ''
  try {
    await apiRequest(`/users/${u.id}${path}`, { method })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Action failed'
  } finally {
    busy.value = false
  }
}
const suspend = (u: UserRow) => act(u, '/suspend')
const reactivate = (u: UserRow) => act(u, '/reactivate')
async function approve(u: UserRow) {
  // Approve = activate; open the editor first so roles can be set.
  openEdit(u)
  form.value.status = 'active'
}
async function remove(u: UserRow) {
  if (!confirm(`Delete user "${u.name}"? This cannot be undone.`)) return
  await act(u, '', 'DELETE')
}

const badge: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-700',
  active: 'bg-emerald-100 text-emerald-700',
  suspended: 'bg-rose-100 text-rose-700',
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-2 flex-wrap">
      <div class="flex items-center gap-2">
        <button v-for="f in (['all', 'pending', 'active', 'suspended'] as const)" :key="f" @click="filter = f"
          class="px-3 py-1.5 rounded-lg text-sm font-medium transition capitalize"
          :class="filter === f ? 'bg-airr-500 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'">
          {{ f }}
          <span v-if="f === 'pending' && pendingCount" class="ml-1 inline-flex items-center justify-center text-xs rounded-full bg-amber-400 text-white w-5 h-5">{{ pendingCount }}</span>
        </button>
      </div>
      <button @click="openCreate" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New User</button>
    </div>

    <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

    <div class="bg-white rounded-xl border border-slate-100 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
          <tr>
            <th class="px-4 py-3 font-medium">User</th>
            <th class="px-4 py-3 font-medium">Type</th>
            <th class="px-4 py-3 font-medium">Roles</th>
            <th class="px-4 py-3 font-medium">Status</th>
            <th class="px-4 py-3 font-medium text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="5" class="px-4 py-8 text-center text-slate-400">Loading…</td></tr>
          <tr v-else-if="!filtered.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">No users.</td></tr>
          <tr v-for="u in filtered" :key="u.id" class="hover:bg-slate-50 align-top">
            <td class="px-4 py-3">
              <div class="flex items-center gap-3">
                <UserAvatar :name="u.name" :src="u.avatar_url" :size="36" />
                <div>
                  <div class="font-medium text-slate-700">{{ u.name }}</div>
                  <div class="text-slate-400 text-xs">{{ u.email }}</div>
                  <span class="text-[10px] uppercase tracking-wide text-slate-400">{{ u.auth_provider }}</span>
                </div>
              </div>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ typeLabel(u.user_type) }}</td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap gap-1">
                <span v-for="r in u.roles" :key="r.id" class="text-xs bg-slate-100 text-slate-600 rounded-full px-2 py-0.5">{{ r.name }}</span>
                <span v-if="!u.roles.length" class="text-xs text-slate-300">—</span>
              </div>
            </td>
            <td class="px-4 py-3">
              <span class="text-xs font-medium rounded-full px-2 py-0.5 capitalize" :class="badge[u.status]">{{ u.status }}</span>
              <div class="text-[10px] text-slate-400 mt-1">clearance {{ u.clearance }}</div>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <button v-if="u.status === 'pending'" @click="approve(u)" :disabled="busy"
                class="text-xs font-medium text-white bg-emerald-500 hover:bg-emerald-600 rounded-lg px-3 py-1.5">Approve</button>
              <button @click="openEdit(u)" :disabled="busy" class="text-xs text-airr-600 hover:underline ml-2">Edit</button>
              <button v-if="u.status === 'active'" @click="suspend(u)" :disabled="busy" class="text-xs text-rose-600 hover:underline ml-2">Suspend</button>
              <button v-if="u.status === 'suspended'" @click="reactivate(u)" :disabled="busy" class="text-xs text-emerald-600 hover:underline ml-2">Activate</button>
              <button @click="remove(u)" :disabled="busy" class="text-xs text-slate-400 hover:text-rose-600 hover:underline ml-2">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create / Edit modal -->
    <div v-if="showForm" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4 py-8 overflow-y-auto" @click.self="showForm = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6 space-y-4 my-auto">
        <h3 class="font-bold text-lg">{{ editing ? 'Edit User' : 'New User' }}</h3>

        <div v-if="editing" class="flex items-center gap-4">
          <UserAvatar :name="editing.name" :src="editing.avatar_url" :size="56" />
          <label class="text-sm text-airr-600 hover:underline cursor-pointer">
            {{ busy ? 'Uploading…' : 'Change avatar' }}
            <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="onAvatarPick" :disabled="busy" />
          </label>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
            <input v-model="form.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Email</label>
            <div class="relative">
              <input v-model="form.email" type="email"
                class="w-full rounded-lg border px-3 py-2 pr-10 focus:ring-2 outline-none"
                :class="emailValid ? 'border-slate-200 focus:ring-airr-300' : 'border-rose-300 focus:ring-rose-300'" />
              <button type="button" @click="testEmail" title="Test email"
                class="absolute inset-y-0 right-2 flex items-center text-slate-400 hover:text-airr-600">
                <component :is="MailCheck" :size="17" />
              </button>
            </div>
            <p v-if="!emailValid" class="text-xs text-rose-500 mt-1">Invalid email format.</p>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">
            Password <span class="text-slate-400 font-normal">{{ editing ? '(leave blank to keep)' : '(min 8 chars)' }}</span>
          </label>
          <div class="relative">
            <input v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password"
              class="w-full rounded-lg border border-slate-200 px-3 py-2 pr-10 focus:ring-2 focus:ring-airr-300 outline-none" />
            <button type="button" @click="showPassword = !showPassword" :title="showPassword ? 'Hide' : 'Show'"
              class="absolute inset-y-0 right-2 flex items-center text-slate-400 hover:text-slate-600">
              <component :is="showPassword ? EyeOff : Eye" :size="17" />
            </button>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">User Type</label>
            <select v-model="form.user_type" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
              <option v-for="t in USER_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Status</label>
            <select v-model="form.status" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
              <option value="active">Active</option>
              <option value="pending">Pending</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Roles</label>
          <div class="grid grid-cols-2 gap-1.5 max-h-40 overflow-y-auto border border-slate-100 rounded-lg p-2">
            <label v-for="r in roles" :key="r.id" class="flex items-center gap-2 text-sm">
              <input type="checkbox" :checked="form.roles.includes(r.id)" @change="toggleRole(r.id)" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
              <span>{{ r.name }}</span>
            </label>
            <span v-if="!roles.length" class="text-xs text-slate-400">No roles defined.</span>
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button @click="showForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="save" :disabled="busy || !form.name || !form.email || (!editing && !form.password)"
            class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busy ? 'Saving…' : editing ? 'Save' : 'Create User' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
