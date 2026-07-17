<script setup>
import { RouterLink } from 'vue-router'
</script>

<template>
  <section class="mx-auto max-w-lg space-y-4 rounded-md border border-slate-200 bg-white p-6 text-sm dark:border-slate-700 dark:bg-slate-900">
    <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Acceso denegado</h1>
    <p class="text-slate-600 dark:text-slate-300">
      No tenés permiso para acceder a esta sección.
    </p>
    <RouterLink
      to="/"
      class="inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
    >
      Volver al inicio
    </RouterLink>
  </section>
</template>
