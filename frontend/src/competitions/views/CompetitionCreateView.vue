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
  points_per_set: 11,
  qualified_per_group: 2,
  group_stage_best_of: 5,
  knockout_stage_best_of: 5,
  semifinal_best_of: 7,
  final_best_of: 7,
})

const bestOfOptions = [1, 3, 5, 7]

const submit = async () => {
  isSubmitting.value = true
  errorMessage.value = ''

  const payload = {
    name: form.name,
    category: form.category,
    type: form.type,
    format: form.format,
    points_per_set: Number(form.points_per_set),
    qualified_per_group: Number(form.qualified_per_group),
    group_stage_best_of: Number(form.group_stage_best_of),
    knockout_stage_best_of: Number(form.knockout_stage_best_of),
    semifinal_best_of: Number(form.semifinal_best_of),
    final_best_of: Number(form.final_best_of),
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
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Nueva competencia</h1>
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

      <fieldset class="space-y-3 rounded-md border border-slate-200 p-3 dark:border-slate-700">
        <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-200">Formato de partidos</legend>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="space-y-1">
            <label class="block text-sm text-slate-600 dark:text-slate-300" for="group_stage_best_of">
              Fase de grupos: mejor de
            </label>
            <select
              id="group_stage_best_of"
              v-model.number="form.group_stage_best_of"
              required
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
            >
              <option v-for="option in bestOfOptions" :key="`group-${option}`" :value="option">
                {{ option }}
              </option>
            </select>
          </div>

          <div class="space-y-1">
            <label class="block text-sm text-slate-600 dark:text-slate-300" for="knockout_stage_best_of">
              Eliminatorias tempranas: mejor de
            </label>
            <select
              id="knockout_stage_best_of"
              v-model.number="form.knockout_stage_best_of"
              required
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
            >
              <option v-for="option in bestOfOptions" :key="`knockout-${option}`" :value="option">
                {{ option }}
              </option>
            </select>
          </div>

          <div class="space-y-1">
            <label class="block text-sm text-slate-600 dark:text-slate-300" for="semifinal_best_of">
              Semifinal: mejor de
            </label>
            <select
              id="semifinal_best_of"
              v-model.number="form.semifinal_best_of"
              required
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
            >
              <option v-for="option in bestOfOptions" :key="`semifinal-${option}`" :value="option">
                {{ option }}
              </option>
            </select>
          </div>

          <div class="space-y-1">
            <label class="block text-sm text-slate-600 dark:text-slate-300" for="final_best_of">
              Final: mejor de
            </label>
            <select
              id="final_best_of"
              v-model.number="form.final_best_of"
              required
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
            >
              <option v-for="option in bestOfOptions" :key="`final-${option}`" :value="option">
                {{ option }}
              </option>
            </select>
          </div>
        </div>
      </fieldset>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="isSubmitting"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
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
