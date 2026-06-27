<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  name: string
  code?: string
  color?: string | null
  size?: number
}>(), { size: 32, color: null, code: '' })

const PALETTE = ['#E11D48', '#F97316', '#EAB308', '#22C55E', '#06B6D4', '#3B82F6', '#8B5CF6', '#EC4899', '#64748B', '#0F766E']

const bg = computed(() => {
  if (props.color) return props.color
  const seed = props.code || props.name || 'P'
  let h = 0
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) % PALETTE.length
  return PALETTE[h]
})

const initials = computed(() =>
  props.name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? '').join('') || '?',
)
</script>

<template>
  <span class="rounded-lg shrink-0 inline-flex items-center justify-center text-white font-bold"
    :style="{ background: bg, width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.38)}px` }">
    {{ initials }}
  </span>
</template>
