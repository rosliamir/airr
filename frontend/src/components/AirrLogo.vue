<script setup lang="ts">
// Spark (generation) + 2 nodes (retrieval = data + documents).
defineProps<{ size?: number }>()
</script>

<template>
  <svg :width="size ?? 36" :height="size ?? 36" viewBox="0 0 48 48" fill="none">
    <defs>
      <linearGradient id="airr-g" x1="0" y1="0" x2="48" y2="48">
        <stop offset="0" stop-color="#FB7185" />
        <stop offset="0.5" stop-color="#E11D48" />
        <stop offset="1" stop-color="#9F1239" />
      </linearGradient>
    </defs>
    <rect x="2" y="2" width="44" height="44" rx="12" fill="url(#airr-g)" />
    <path d="M24 11 L27 21 L37 24 L27 27 L24 37 L21 27 L11 24 L21 21 Z" fill="white" />
    <circle cx="14" cy="34" r="3" fill="white" opacity="0.9" />
    <circle cx="34" cy="14" r="3" fill="white" opacity="0.9" />
  </svg>
</template>
