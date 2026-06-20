<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  fallbackTo: {
    type: String,
    default: '/',
  },
  label: {
    type: String,
    default: 'Volver',
  },
})

const router = useRouter()

const canGoBack = computed(() => {
  if (typeof window === 'undefined') {
    return false
  }

  return window.history.length > 1
})

const handleBack = async () => {
  if (canGoBack.value) {
    router.back()
    return
  }

  await router.push(props.fallbackTo || '/')
}
</script>

<template>
  <button
    type="button"
    class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
    @click="handleBack"
  >
    {{ label }}
  </button>
</template>
