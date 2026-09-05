<script setup>
import { EyeIcon } from '@heroicons/vue/24/outline'
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import AppTooltip from '../../components/AppTooltip.vue'
import RankingService from '../services/RankingService'
import {
  rankingCategoryLabel,
  rankingStatusBadgeClasses,
  rankingStatusLabel,
  rankingTypeLabel,
} from '../utils/rankingDisplay'

const rankings = ref([])
const isLoading = ref(false)
const errorMessage = ref('')

const viewButtonClasses =
  'inline-flex rounded-md border border-blue-300 bg-blue-50 p-1.5 text-blue-700 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-950/60'

const retryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const loadRankings = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    rankings.value = await RankingService.index()
  } catch {
    errorMessage.value = 'No se pudo cargar el ranking.'
    rankings.value = []
  } finally {
    isLoading.value = false
  }
}

onMounted(loadRankings)
</script>

<template>
  <section class="min-w-0 space-y-4">
    <AppBreadcrumbs />

    <div>
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Rankings</h1>
      <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
        Consultá las tablas de puntuación acumulada.
      </p>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando rankings...</p>

    <div v-else-if="errorMessage" class="space-y-3">
      <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
      <button type="button" :class="retryButtonClasses" @click="loadRankings">Reintentar</button>
    </div>

    <div
      v-else-if="rankings.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      Todavía no hay rankings cargados.
    </div>

    <div
      v-else
      class="w-full overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
      <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
        <thead class="bg-slate-50 dark:bg-slate-800">
          <tr>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Ranking
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Modalidad
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Temporada
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Categoría
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Estado
            </th>
            <th
              class="w-40 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Acciones
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
          <tr
            v-for="ranking in rankings"
            :key="ranking.id"
            class="hover:bg-slate-50 dark:hover:bg-slate-800"
          >
            <td class="px-4 py-3 text-sm">
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ ranking.name }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ rankingTypeLabel(ranking.competition_type) }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ ranking.season || '-' }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ rankingCategoryLabel(ranking.category) }}
            </td>
            <td class="px-4 py-3 text-sm">
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="rankingStatusBadgeClasses(ranking.active)"
              >
                {{ rankingStatusLabel(ranking.active) }}
              </span>
            </td>
            <td class="w-40 px-4 py-3 text-sm">
              <div class="flex flex-nowrap items-center justify-end gap-1.5">
                <AppTooltip label="Ver ranking">
                  <RouterLink
                    :to="{ name: 'rankings.show', params: { id: ranking.id } }"
                    :class="viewButtonClasses"
                    aria-label="Ver ranking"
                  >
                    <EyeIcon class="h-4 w-4" aria-hidden="true" />
                  </RouterLink>
                </AppTooltip>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
