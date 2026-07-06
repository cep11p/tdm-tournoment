<script setup>
defineProps({
  label: {
    type: String,
    required: true,
  },
})
</script>

<template>
  <span class="group relative inline-flex max-w-full">
    <slot />
    <span
      role="tooltip"
      class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1.5 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white shadow-sm group-focus-within:block group-hover:block dark:bg-slate-100 dark:text-slate-900"
    >
      {{ label }}
    </span>
  </span>
</template>
