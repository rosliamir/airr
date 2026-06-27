<script setup lang="ts">
import { ref } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import UsersTab from '../components/users/UsersTab.vue'
import RolesTab from '../components/users/RolesTab.vue'

const tab = ref<'users' | 'roles'>('users')
const tabs = [
  { key: 'users', label: 'Users' },
  { key: 'roles', label: 'Roles' },
] as const
</script>

<template>
  <AdminLayout>
    <div class="space-y-5">
      <!-- Tab bar -->
      <div class="flex gap-6 border-b border-slate-200">
        <button v-for="t in tabs" :key="t.key" @click="tab = t.key"
          class="pb-2.5 text-sm font-medium border-b-2 -mb-px transition"
          :class="tab === t.key ? 'border-airr-500 text-airr-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
          {{ t.label }}
        </button>
      </div>

      <UsersTab v-if="tab === 'users'" />
      <RolesTab v-else-if="tab === 'roles'" />
    </div>
  </AdminLayout>
</template>
