<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import RankingService from '../services/RankingService'
import {
  formatRankingDate,
  formatRankingHistorySummary,
  formatRankingPoints,
  rankingTransactionResultLabel,
} from '../utils/rankingDisplay'

const route = useRoute()

const history = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

const retryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const linkClasses =
  'font-medium text-slate-900 hover:underline dark:text-slate-100'

const rankingId = computed(() => route.params.id)
const playerId = computed(() => route.params.playerId)

const ranking = computed(() => history.value?.ranking ?? null)
const player = computed(() => history.value?.player ?? null)
const summary = computed(() => history.value?.summary ?? null)
const transactions = computed(() => history.value?.transactions ?? [])

const breadcrumbContext = computed(() => ({
  rankingName: ranking.value?.name,
  playerName: player.value?.display_name,
}))

const summaryLabel = computed(() => {
  if (!summary.value) {
    return null
  }

  return formatRankingHistorySummary(summary.value.points, summary.value.events_count)
})

const backFallback = computed(() =>
  rankingId.value ? `/rankings/${rankingId.value}` : '/rankings',
)

const loadHistory = async () => {
  const id = rankingId.value
  const playerKey = playerId.value

  if (!id || !playerKey) {
    history.value = null
    errorMessage.value = 'No se pudo cargar el historial del ranking.'
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    history.value = await RankingService.playerTransactions(id, playerKey)
  } catch {
    errorMessage.value = 'No se pudo cargar el historial del ranking.'
    history.value = null
  } finally {
    isLoading.value = false
  }
}

watch([rankingId, playerId], loadHistory, { immediate: true })
</script>

<template>
  <section class="min-w-0 space-y-4">
    <AppBreadcrumbs :context="breadcrumbContext" />

    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
          {{ player?.display_name || 'Jugador' }}
        </h1>
        <p
          v-if="ranking"
          class="mt-1 text-sm text-slate-600 dark:text-slate-400"
        >
          {{ ranking.name }}
        </p>
        <p
          v-if="summaryLabel"
          class="mt-0.5 text-sm text-slate-600 dark:text-slate-400"
        >
          {{ summaryLabel }}
        </p>
      </div>

      <AppBackButton :fallback-to="backFallback" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">
      Cargando historial...
    </p>

    <div v-else-if="errorMessage" class="space-y-3">
      <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
      <button type="button" :class="retryButtonClasses" @click="loadHistory">
        Reintentar
      </button>
    </div>

    <template v-else-if="history">
      <div
        v-if="transactions.length === 0"
        class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
      >
        Todavía no hay movimientos registrados para este jugador.
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
                Fecha
              </th>
              <th
                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Torneo
              </th>
              <th
                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Competencia
              </th>
              <th
                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Resultado
              </th>
              <th
                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Puntos
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
            <tr
              v-for="row in transactions"
              :key="row.id || `${row.competition_id}-${row.date}`"
              class="hover:bg-slate-50 dark:hover:bg-slate-800"
            >
              <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
                {{ formatRankingDate(row.date) || '-' }}
              </td>
              <td class="px-4 py-3 text-sm">
                <RouterLink
                  v-if="row.tournament_id"
                  :to="{ name: 'tournaments-detail', params: { id: row.tournament_id } }"
                  :class="linkClasses"
                >
                  {{ row.tournament_name || 'Torneo' }}
                </RouterLink>
                <span
                  v-else
                  class="font-medium text-slate-900 dark:text-slate-100"
                >
                  {{ row.tournament_name || '-' }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm">
                <RouterLink
                  v-if="row.competition_id"
                  :to="{ name: 'competitions-detail', params: { id: row.competition_id } }"
                  :class="linkClasses"
                >
                  {{ row.competition_name || 'Competencia' }}
                </RouterLink>
                <span
                  v-else
                  class="font-medium text-slate-900 dark:text-slate-100"
                >
                  {{ row.competition_name || '-' }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
                {{ rankingTransactionResultLabel(row) }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">
                {{ formatRankingPoints(row.points) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>
