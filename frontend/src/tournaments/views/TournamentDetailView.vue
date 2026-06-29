<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import CompetitionService from '../../competitions/services/CompetitionService'
import {
  getStatusBadgeClasses,
  getStatusLabel,
  getStructurePrimary,
  getStructureSecondary,
} from '../../competitions/utils/competitionListDisplay'
import TournamentService from '../services/TournamentService'

const route = useRoute()

const tournament = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

const competitions = ref([])
const isLoadingCompetitions = ref(false)
const competitionsErrorMessage = ref('')

const STATUS_LABELS = {
  draft: 'Draft',
  in_progress: 'En curso',
  finished: 'Finalizado',
}

const getTournamentStatusLabel = (status) => {
  if (!status) {
    return '-'
  }

  return STATUS_LABELS[status] ?? status
}

const getTournamentStatusBadgeClasses = (status) => {
  switch (status) {
    case 'in_progress':
      return 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200'
    case 'finished':
      return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
    case 'draft':
    default:
      return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
  }
}

const competitionsRoute = computed(() =>
  tournament.value?.id ? `/tournaments/${tournament.value.id}/competitions` : null,
)

const createCompetitionRoute = computed(() =>
  tournament.value?.id ? `/tournaments/${tournament.value.id}/competitions/create` : null,
)

const loadTournament = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    tournament.value = await TournamentService.show(route.params.id)
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar el torneo.'
  } finally {
    isLoading.value = false
  }
}

const loadCompetitions = async () => {
  isLoadingCompetitions.value = true
  competitionsErrorMessage.value = ''

  try {
    competitions.value = await CompetitionService.listByTournament(route.params.id)
  } catch (error) {
    competitionsErrorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar el listado de competencias.'
  } finally {
    isLoadingCompetitions.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadTournament(), loadCompetitions()])
})
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs :context="{ tournamentId: route.params.id, tournamentName: tournament?.name }" />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ tournament?.name || `Torneo #${route.params.id}` }}
      </h1>
      <RouterLink
        to="/tournaments"
        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
      >
        Volver a torneos
      </RouterLink>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-400">Cargando torneo...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <template v-else-if="tournament">
      <div
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-900 dark:text-slate-100">Información del torneo</p>

        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Estado</dt>
            <dd class="mt-1">
              <span
                v-if="tournament.status"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="getTournamentStatusBadgeClasses(tournament.status)"
              >
                {{ getTournamentStatusLabel(tournament.status) }}
              </span>
              <span v-else class="font-medium text-slate-900 dark:text-slate-100">-</span>
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Fecha inicio</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">
              {{ tournament.start_date || '-' }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Fecha fin</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">
              {{ tournament.end_date || '-' }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Ubicación</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">
              {{ tournament.location || '-' }}
            </dd>
          </div>
        </dl>
      </div>

      <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Competencias del torneo</h2>
          <RouterLink
            v-if="competitionsRoute"
            :to="competitionsRoute"
            class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          >
            Administrar competencias
          </RouterLink>
        </div>

        <p v-if="isLoadingCompetitions" class="text-sm text-slate-600 dark:text-slate-400">
          Cargando competencias...
        </p>
        <p v-else-if="competitionsErrorMessage" class="text-sm text-red-600 dark:text-red-400">
          {{ competitionsErrorMessage }}
        </p>

        <div
          v-else-if="competitions.length === 0"
          class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
        >
          <p class="text-slate-600 dark:text-slate-300">
            Este torneo todavía no tiene competencias cargadas.
          </p>
          <RouterLink
            v-if="createCompetitionRoute"
            :to="createCompetitionRoute"
            class="mt-3 inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          >
            Crear competencia
          </RouterLink>
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
                  <p
                    v-if="competition.category || competition.type"
                    class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
                  >
                    {{ [competition.category, competition.type].filter(Boolean).join(' · ') }}
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
      </div>
    </template>
  </section>
</template>
