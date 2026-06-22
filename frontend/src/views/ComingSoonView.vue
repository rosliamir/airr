<script setup lang="ts">
import { useRoute } from 'vue-router'
import * as icons from 'lucide-vue-next'
import AdminLayout from '../layouts/AdminLayout.vue'

const route = useRoute()
const module = (route.meta.module as string) ?? ''
const phase = (route.meta.phase as number) ?? ''
</script>

<template>
  <AdminLayout>
    <div class="max-w-xl mx-auto mt-16 text-center">
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-airr-50 text-airr-500 mb-4">
        <component :is="icons.Hammer" :size="28" />
      </div>
      <h2 class="text-xl font-semibold">{{ route.meta.title }}</h2>
      <p class="mt-2 text-slate-500">
        Modul <span class="font-mono font-medium">{{ module }}</span> — akan dibina dalam
        <span class="font-medium text-airr-600">Phase {{ phase }}</span>.
      </p>
      <p class="mt-1 text-sm text-slate-400">Skrin ini placeholder sementara menunggu pelaksanaan.</p>
    </div>
  </AdminLayout>
</template>
