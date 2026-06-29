<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import TournamentService from '../services/TournamentService'

const route = useRoute()

const tournament = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

const STATUS_LABELS = {
  draft: 'Draft',
  in_progress: 'En curso',
  finished: 'Finalizado',
}

const getStatusLabel = (status) => {
  if (!status) {
    return '-'
  }

  return STATUS_LABELS[status] ?? status
}

const getStatusBadgeClasses = (status) => {
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

onMounted(loadTournament)
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
      <div>
        <p class="mb-3 text-sm font-medium text-slate-700 dark:text-slate-200">Acciones principales</p>
        <RouterLink
          v-if="competitionsRoute"
          :to="competitionsRoute"
          class="inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
        >
          Administrar competencias
        </RouterLink>
      </div>

      <div
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-900 dark:text-slate-100">Información del torneo</p>

        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Nombre</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">{{ tournament.name }}</dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Ubicación</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">
              {{ tournament.location || '-' }}
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
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Estado</dt>
            <dd class="mt-1">
              <span
                v-if="tournament.status"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="getStatusBadgeClasses(tournament.status)"
              >
                {{ getStatusLabel(tournament.status) }}
              </span>
              <span v-else class="font-medium text-slate-900 dark:text-slate-100">-</span>
            </dd>
          </div>
        </dl>
      </div>
    </template>
  </section>
</template>
