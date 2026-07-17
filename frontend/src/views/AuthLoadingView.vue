<script setup>
import { computed } from 'vue'

import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()

const loadingMessage = computed(() => {
  if (authStore.configError) {
    return null
  }

  if (!authStore.isReady) {
    return 'Iniciando sesión…'
  }

  if (authStore.isLoadingProfile) {
    return 'Cargando perfil…'
  }

  return 'Preparando la aplicación…'
})

const handleRetry = async () => {
  await authStore.retryProfile()
}
</script>

<template>
  <div
    class="flex min-h-screen items-center justify-center bg-slate-100 p-6 text-slate-900 dark:bg-slate-950 dark:text-slate-100"
  >
    <div class="w-full max-w-md space-y-4 rounded-md border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <h1 class="text-lg font-semibold">Torneos TDM</h1>

      <p v-if="authStore.configError" class="text-sm text-red-600 dark:text-red-400">
        {{ authStore.configError }}
      </p>

      <template v-else-if="authStore.error">
        <p class="text-sm text-red-600 dark:text-red-400">
          {{ authStore.error }}
        </p>
        <button
          type="button"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          @click="handleRetry"
        >
          Reintentar
        </button>
      </template>

      <template v-else>
        <p class="text-sm text-slate-600 dark:text-slate-300">
          {{ loadingMessage }}
        </p>
        <div
          class="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800"
          aria-hidden="true"
        >
          <div class="h-full w-1/3 animate-pulse rounded-full bg-slate-500 dark:bg-slate-400" />
        </div>
      </template>
    </div>
  </div>
</template>
