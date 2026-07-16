<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import CompetitionService from '../services/CompetitionService'
import {
  getStatusBadgeClasses,
  getStatusLabel,
  getStructurePrimary,
  getStructureSecondary,
} from '../utils/competitionListDisplay'
import { getCompetitionTypeLabel } from '../../shared/constants/competitionType'

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
      class="overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
      <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
        <thead class="bg-slate-50 dark:bg-slate-800">
          <tr>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Competencia
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Estructura
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Estado
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Acciones
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
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ competition.name }}</p>
              <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                {{ competition.category }} · {{ getCompetitionTypeLabel(competition.type) }}
              </p>
              <p
                v-if="competition.result_summary?.champion?.name"
                class="mt-0.5 text-xs text-emerald-700 dark:text-emerald-400"
              >
                Campeón: {{ competition.result_summary.champion.name }}
              </p>
            </td>
            <td class="px-4 py-3 text-sm">
              <p class="text-slate-900 dark:text-slate-100">{{ getStructurePrimary(competition) }}</p>
              <p
                v-if="getStructureSecondary(competition)"
                class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
              >
                {{ getStructureSecondary(competition) }}
              </p>
            </td>
            <td class="px-4 py-3 text-sm">
              <span
                v-if="competition.status_summary"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="getStatusBadgeClasses(competition)"
              >
                {{ getStatusLabel(competition) }}
              </span>
              <span v-else class="text-slate-400 dark:text-slate-500">-</span>
            </td>
            <td class="px-4 py-3 text-sm">
              <RouterLink
                :to="`/competitions/${competition.id}`"
                class="font-medium text-slate-900 hover:underline dark:text-slate-100"
              >
                Gestionar
              </RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
