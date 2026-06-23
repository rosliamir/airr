<script setup lang="ts">
// Light, sovereign scenery backdrop — Kuala Lumpur skyline silhouette
// (Petronas Twin Towers, KL Tower) over a soft Malaysian dawn sky.
//
// Default is pure inline SVG: no external fetch, air-gap safe. To use a real
// photo, import a BUNDLED local asset (NOT an external URL — that breaks the
// sovereign/air-gap constraint) and pass it as `src`:
//
//   import bg from '../assets/kl-dawn.jpg'
//   <MalaysiaBackdrop :src="bg" />
//
// The image is fingerprinted and served by our own origin at build time.
withDefaults(defineProps<{ src?: string }>(), { src: '' })
</script>

<template>
  <div class="fixed inset-0 -z-10 overflow-hidden">
    <!-- Local bundled photo (air-gap safe). -->
    <img v-if="src" :src="src" alt="" class="w-full h-full object-cover" />

    <!-- Inline-SVG fallback when no photo is supplied. -->
    <svg v-else class="w-full h-full" preserveAspectRatio="xMidYMax slice" viewBox="0 0 1440 900" fill="none">
      <defs>
        <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#fff7f8" />
          <stop offset="0.45" stop-color="#ffeef0" />
          <stop offset="0.8" stop-color="#fde4e8" />
          <stop offset="1" stop-color="#fbd5dc" />
        </linearGradient>
        <radialGradient id="sun" cx="0.78" cy="0.28" r="0.35">
          <stop offset="0" stop-color="#ffffff" stop-opacity="0.9" />
          <stop offset="0.5" stop-color="#fecdd3" stop-opacity="0.5" />
          <stop offset="1" stop-color="#fecdd3" stop-opacity="0" />
        </radialGradient>
      </defs>

      <!-- Sky -->
      <rect width="1440" height="900" fill="url(#sky)" />
      <rect width="1440" height="900" fill="url(#sun)" />
      <circle cx="1123" cy="252" r="46" fill="#fff1f2" opacity="0.95" />

      <!-- Far hills -->
      <path d="M0 640 Q 240 580 480 620 T 960 612 T 1440 632 V900 H0 Z" fill="#fbcfd6" opacity="0.6" />
      <path d="M0 690 Q 360 640 720 680 T 1440 678 V900 H0 Z" fill="#f9bcc6" opacity="0.5" />

      <!-- City skyline (soft crimson silhouette) -->
      <g fill="#e8a3b0" opacity="0.55">
        <rect x="120" y="640" width="46" height="160" />
        <rect x="180" y="600" width="34" height="200" />
        <rect x="226" y="660" width="40" height="140" />
        <rect x="300" y="620" width="30" height="180" />
        <rect x="1160" y="640" width="42" height="160" />
        <rect x="1212" y="600" width="32" height="200" />
        <rect x="1256" y="662" width="44" height="138" />
        <rect x="1316" y="624" width="30" height="176" />
      </g>

      <!-- KL Tower (Menara KL) -->
      <g fill="#d98796" opacity="0.7">
        <rect x="404" y="470" width="10" height="330" />
        <ellipse cx="409" cy="468" rx="26" ry="16" />
        <path d="M403 452 h12 l-2 18 h-8 z" />
        <rect x="407" y="430" width="4" height="24" />
      </g>

      <!-- Petronas Twin Towers -->
      <g fill="#cf6f80" opacity="0.85">
        <!-- left tower -->
        <rect x="876" y="360" width="44" height="440" />
        <rect x="884" y="330" width="28" height="34" rx="3" />
        <rect x="894" y="300" width="8" height="36" />
        <!-- skybridge -->
        <rect x="920" y="470" width="60" height="10" />
        <rect x="944" y="470" width="12" height="40" />
        <!-- right tower -->
        <rect x="980" y="360" width="44" height="440" />
        <rect x="988" y="330" width="28" height="34" rx="3" />
        <rect x="998" y="300" width="8" height="36" />
      </g>

      <!-- Palm trees (foreground, light) -->
      <g stroke="#c98593" stroke-width="5" stroke-linecap="round" opacity="0.6" fill="none">
        <path d="M70 800 Q 66 740 64 712" />
        <path d="M64 712 q -34 -8 -52 4 M64 712 q -30 -22 -54 -22 M64 712 q 28 -10 50 0 M64 712 q 24 -24 50 -22 M64 712 q 0 -26 2 -40" />
      </g>

      <!-- Birds -->
      <g stroke="#b06b7a" stroke-width="3" fill="none" opacity="0.4" stroke-linecap="round">
        <path d="M1040 180 q 10 -8 20 0 q 10 -8 20 0" />
        <path d="M1090 210 q 8 -6 16 0 q 8 -6 16 0" />
      </g>
    </svg>
  </div>
</template>
