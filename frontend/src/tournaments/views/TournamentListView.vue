<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import TournamentService from '../services/TournamentService'

const tournaments = ref([])
const isLoading = ref(false)
const errorMessage = ref('')

const loadTournaments = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    tournaments.value = await TournamentService.list()
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar el listado de torneos.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadTournaments)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Tournaments</h1>
      <RouterLink
        to="/tournaments/create"
        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
      >
        Nuevo torneo
      </RouterLink>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando torneos...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <div
      v-else-if="tournaments.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      No hay torneos cargados.
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
              Fecha inicio
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Fecha fin
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
          <tr
            v-for="tournament in tournaments"
            :key="tournament.id"
            class="hover:bg-slate-50 dark:hover:bg-slate-800"
          >
            <td class="px-4 py-3 text-sm">
              <RouterLink
                :to="`/tournaments/${tournament.id}`"
                class="font-medium text-slate-900 hover:underline dark:text-slate-100"
              >
                {{ tournament.name }}
              </RouterLink>
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ tournament.start_date || '-' }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ tournament.end_date || '-' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
