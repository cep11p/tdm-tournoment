<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

import TournamentService from '../services/TournamentService'

const router = useRouter()
const isSubmitting = ref(false)
const errorMessage = ref('')

const form = reactive({
  name: '',
  location: '',
  start_date: '',
  end_date: '',
  status: 'draft',
})

const submit = async () => {
  isSubmitting.value = true
  errorMessage.value = ''

  const payload = {
    name: form.name,
    location: form.location,
    start_date: form.start_date,
    ...(form.end_date ? { end_date: form.end_date } : {}),
    ...(form.status ? { status: form.status } : {}),
  }

  try {
    await TournamentService.create(payload)
    await router.push('/tournaments')
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo crear el torneo.'
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Nuevo torneo</h1>
    </div>

    <p class="text-sm text-slate-600 dark:text-slate-300">Formulario inicial para crear un torneo.</p>
    <p v-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <form
      class="max-w-xl space-y-4 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
      @submit.prevent="submit"
    >
      <div class="space-y-1">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="name">Nombre</label>
        <input
          id="name"
          v-model="form.name"
          type="text"
          required
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
        />
      </div>

      <div class="space-y-1">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="location">Ubicación</label>
        <input
          id="location"
          v-model="form.location"
          type="text"
          required
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
        />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="start_date">
            Fecha inicio
          </label>
          <input
            id="start_date"
            v-model="form.start_date"
            type="date"
            required
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
          />
        </div>

        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="end_date">Fecha fin</label>
          <input
            id="end_date"
            v-model="form.end_date"
            type="date"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
          />
        </div>
      </div>

      <div class="space-y-1">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="status">Estado</label>
        <select
          id="status"
          v-model="form.status"
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
        >
          <option value="draft">draft</option>
          <option value="in_progress">in_progress</option>
          <option value="finished">finished</option>
        </select>
      </div>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="isSubmitting"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
        >
          {{ isSubmitting ? 'Guardando...' : 'Guardar' }}
        </button>

        <RouterLink to="/tournaments" class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-300">
          Cancelar
        </RouterLink>
      </div>
    </form>
  </section>
</template>
