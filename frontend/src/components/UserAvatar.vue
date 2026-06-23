<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{ name: string; src?: string | null; size?: number }>(), {
  src: null,
  size: 36,
})

const initials = computed(() =>
  props.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? '')
    .join(''),
)

// Deterministic colour from the name so each user keeps a stable hue.
const bg = computed(() => {
  let h = 0
  for (let i = 0; i < props.name.length; i++) h = (h * 31 + props.name.charCodeAt(i)) % 360
  return `hsl(${h} 55% 55%)`
})
</script>

<template>
  <img v-if="src" :src="src" :width="size" :height="size" alt=""
    class="rounded-full object-cover shrink-0" :style="{ width: size + 'px', height: size + 'px' }" />
  <span v-else class="rounded-full inline-flex items-center justify-center text-white font-semibold shrink-0 select-none"
    :style="{ width: size + 'px', height: size + 'px', background: bg, fontSize: size * 0.4 + 'px' }">
    {{ initials }}
  </span>
</template>
