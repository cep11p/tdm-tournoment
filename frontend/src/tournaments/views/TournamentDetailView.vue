<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import TournamentService from '../services/TournamentService'

const route = useRoute()

const tournament = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

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
      <AppBackButton fallback-to="/tournaments" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-400">Cargando torneo...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <div
      v-else-if="tournament"
      class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
    >
      <div>
        <RouterLink
          :to="`/tournaments/${tournament.id}/competitions`"
          class="inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
        >
          Administrar competencias
        </RouterLink>
      </div>

      <div>
        <p class="text-slate-500 dark:text-slate-400">Nombre</p>
        <p class="font-medium text-slate-900 dark:text-slate-100">{{ tournament.name }}</p>
      </div>

      <div>
        <p class="text-slate-500 dark:text-slate-400">Ubicación</p>
        <p class="font-medium text-slate-900 dark:text-slate-100">{{ tournament.location }}</p>
      </div>

      <div>
        <p class="text-slate-500 dark:text-slate-400">Fecha inicio</p>
        <p class="font-medium text-slate-900 dark:text-slate-100">{{ tournament.start_date || '-' }}</p>
      </div>

      <div>
        <p class="text-slate-500 dark:text-slate-400">Fecha fin</p>
        <p class="font-medium text-slate-900 dark:text-slate-100">{{ tournament.end_date || '-' }}</p>
      </div>

      <div>
        <p class="text-slate-500 dark:text-slate-400">Estado</p>
        <p class="font-medium text-slate-900 dark:text-slate-100">{{ tournament.status || '-' }}</p>
      </div>
    </div>
  </section>
</template>
