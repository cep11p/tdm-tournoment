<script setup>
import { reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import CompetitionService from '../services/CompetitionService'

const route = useRoute()
const router = useRouter()

const tournamentId = route.params.id
const isSubmitting = ref(false)
const errorMessage = ref('')

const form = reactive({
  name: '',
  category: '',
  type: 'singles',
  format: 'manual',
  sets_to_win: 2,
  points_per_set: 11,
  qualified_per_group: 2,
})

const submit = async () => {
  isSubmitting.value = true
  errorMessage.value = ''

  const payload = {
    name: form.name,
    category: form.category,
    type: form.type,
    format: form.format,
    sets_to_win: Number(form.sets_to_win),
    points_per_set: Number(form.points_per_set),
    qualified_per_group: Number(form.qualified_per_group),
  }

  try {
    await CompetitionService.create(tournamentId, payload)
    await router.push(`/tournaments/${tournamentId}/competitions`)
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo crear la competencia.'
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">Nueva competencia</h1>
    </div>

    <p class="text-sm text-slate-600 dark:text-slate-300">Formulario inicial para crear una competencia.</p>
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
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="category">Categoría</label>
        <input
          id="category"
          v-model="form.category"
          type="text"
          required
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
        />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="type">Tipo</label>
          <select
            id="type"
            v-model="form.type"
            required
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
          >
            <option value="singles">singles</option>
          </select>
        </div>

        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="format">Formato</label>
          <select
            id="format"
            v-model="form.format"
            required
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
          >
            <option value="manual">manual</option>
          </select>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="sets_to_win">
            Sets para ganar
          </label>
          <input
            id="sets_to_win"
            v-model.number="form.sets_to_win"
            type="number"
            min="1"
            required
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
          />
        </div>

        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="points_per_set">
            Puntos por set
          </label>
          <input
            id="points_per_set"
            v-model.number="form.points_per_set"
            type="number"
            min="1"
            required
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
          />
        </div>
      </div>

      <div class="space-y-1">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="qualified_per_group">
          Clasificados por grupo
        </label>
        <input
          id="qualified_per_group"
          v-model.number="form.qualified_per_group"
          type="number"
          min="1"
          required
          class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
        />
      </div>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="isSubmitting"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70"
        >
          {{ isSubmitting ? 'Guardando...' : 'Guardar' }}
        </button>

        <RouterLink
          :to="`/tournaments/${tournamentId}/competitions`"
          class="text-sm font-medium text-slate-700 dark:text-slate-200 hover:underline"
        >
          Cancelar
        </RouterLink>
      </div>
    </form>
  </section>
</template>
