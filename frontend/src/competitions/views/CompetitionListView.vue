<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import CompetitionService from '../services/CompetitionService'
import { getCompetitionFormatLabel } from '../constants/competitionFormats'

const route = useRoute()
const tournamentId = route.params.id

const competitions = ref([])
const isLoading = ref(false)
const errorMessage = ref('')

const loadCompetitions = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    competitions.value = await CompetitionService.listByTournament(tournamentId)
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar el listado de competencias.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadCompetitions)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Competencias del torneo</h1>
      <RouterLink
        :to="`/tournaments/${tournamentId}/competitions/create`"
        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
      >
        Nueva competencia
      </RouterLink>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando competencias...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <div
      v-else-if="competitions.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      No hay competencias cargadas para este torneo.
    </div>

    <div
      v-else
      class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
      <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
        <thead class="bg-slate-50 dark:bg-slate-800">
          <tr>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Nombre
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Categoría
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Tipo
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Formato
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Estado
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
          <tr
            v-for="competition in competitions"
            :key="competition.id"
            class="hover:bg-slate-50 dark:hover:bg-slate-800"
          >
            <td class="px-4 py-3 text-sm">
              <RouterLink
                :to="`/competitions/${competition.id}`"
                class="font-medium text-slate-900 hover:underline dark:text-slate-100"
              >
                {{ competition.name }}
              </RouterLink>
              <p
                v-if="competition.result_summary?.champion?.name"
                class="mt-0.5 text-xs text-emerald-700 dark:text-emerald-400"
              >
                Campeón: {{ competition.result_summary.champion.name }}
              </p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">{{ competition.category }}</td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">{{ competition.type }}</td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ getCompetitionFormatLabel(competition) }}
            </td>
            <td class="px-4 py-3 text-sm">
              <span
                v-if="competition.status_summary?.label"
                class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300"
              >
                {{ competition.status_summary.label }}
              </span>
              <span v-else class="text-slate-400 dark:text-slate-500">-</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
