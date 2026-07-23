<script setup>
import { computed } from 'vue'
import { InformationCircleIcon, LockClosedIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  message: {
    type: String,
    required: true,
  },
  variant: {
    type: String,
    default: 'info',
    validator: (value) => ['info', 'warning'].includes(value),
  },
  useLockIcon: {
    type: Boolean,
    default: false,
  },
})

const containerClasses = computed(() => {
  if (props.variant === 'warning') {
    return 'bg-amber-50/80 text-amber-900 ring-1 ring-amber-200/80 dark:bg-amber-950/20 dark:text-amber-100 dark:ring-amber-800/60'
  }

  return 'bg-slate-50 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800/40 dark:text-slate-300 dark:ring-slate-600/80'
})

const iconClasses = computed(() => {
  if (props.variant === 'warning') {
    return 'text-amber-600 dark:text-amber-400'
  }

  return 'text-slate-500 dark:text-slate-400'
})
</script>

<template>
  <p
    class="flex items-start gap-2 rounded-md px-2.5 py-2 text-xs leading-relaxed"
    :class="containerClasses"
    role="status"
  >
    <LockClosedIcon
      v-if="useLockIcon"
      class="mt-0.5 h-4 w-4 shrink-0"
      :class="iconClasses"
      aria-hidden="true"
    />
    <InformationCircleIcon
      v-else
      class="mt-0.5 h-4 w-4 shrink-0"
      :class="iconClasses"
      aria-hidden="true"
    />
    <span>{{ message }}</span>
  </p>
</template>
