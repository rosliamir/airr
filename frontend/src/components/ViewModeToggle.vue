<script setup lang="ts">
import { LayoutGrid, List } from 'lucide-vue-next'
import type { ListViewMode } from '../composables/useListViewMode'

defineProps<{ modelValue: ListViewMode }>()
const emit = defineEmits<{ 'update:modelValue': [ListViewMode] }>()
</script>

<template>
  <div class="flex items-center rounded-lg border border-slate-200 p-0.5 shrink-0">
    <button type="button" title="Card view" @click="emit('update:modelValue', 'card')"
      class="rounded-md p-1.5 transition"
      :class="modelValue === 'card' ? 'bg-airr-500 text-white' : 'text-slate-400 hover:text-slate-600'">
      <LayoutGrid class="w-3.5 h-3.5" />
    </button>
    <button type="button" title="Table view" @click="emit('update:modelValue', 'table')"
      class="rounded-md p-1.5 transition"
      :class="modelValue === 'table' ? 'bg-airr-500 text-white' : 'text-slate-400 hover:text-slate-600'">
      <List class="w-3.5 h-3.5" />
    </button>
  </div>
</template>
