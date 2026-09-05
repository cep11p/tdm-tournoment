<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import RankingService from '../services/RankingService'
import {
  formatRankingPosition,
  formatRankingValidity,
  rankingCategoryLabel,
  rankingPositionBadgeClasses,
  rankingTypeLabel,
} from '../utils/rankingDisplay'

const route = useRoute()

const ranking = ref(null)
const standings = ref([])
const isLoading = ref(false)
const errorMessage = ref('')

const retryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const rankingId = computed(() => route.params.id)

const breadcrumbContext = computed(() => ({
  rankingName: ranking.value?.name,
}))

const validityLabel = computed(() =>
  formatRankingValidity(ranking.value?.starts_at, ranking.value?.ends_at),
)

const visibleRules = computed(() => {
  const rules = ranking.value?.rules

  if (!Array.isArray(rules)) {
    return []
  }

  return rules.filter((rule) => rule.active !== false && Boolean(rule.name))
})

const loadRanking = async () => {
  const id = rankingId.value

  if (!id) {
    ranking.value = null
    standings.value = []
    errorMessage.value = 'No se pudo cargar el ranking.'
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    const [rankingData, standingsData] = await Promise.all([
      RankingService.show(id),
      RankingService.standings(id),
    ])

    ranking.value = rankingData
    standings.value = standingsData ?? []
  } catch {
    errorMessage.value = 'No se pudo cargar el ranking.'
    ranking.value = null
    standings.value = []
  } finally {
    isLoading.value = false
  }
}

watch(rankingId, loadRanking, { immediate: true })
</script>

<template>
  <section class="min-w-0 space-y-4">
    <AppBreadcrumbs :context="breadcrumbContext" />

    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
          {{ ranking?.name || 'Ranking' }}
        </h1>
        <p
          v-if="ranking"
          class="mt-1 text-sm text-slate-600 dark:text-slate-400"
        >
          {{ rankingTypeLabel(ranking.competition_type) }}
          <template v-if="ranking.season"> · Temporada {{ ranking.season }}</template>
        </p>
        <p v-if="ranking" class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">
          {{ rankingCategoryLabel(ranking.category) }}
        </p>
        <p v-if="validityLabel" class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">
          {{ validityLabel }}
        </p>
      </div>

      <AppBackButton fallback-to="/rankings" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando ranking...</p>

    <div v-else-if="errorMessage" class="space-y-3">
      <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
      <button type="button" :class="retryButtonClasses" @click="loadRanking">Reintentar</button>
    </div>

    <template v-else-if="ranking">
      <details
        v-if="visibleRules.length > 0"
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <summary class="cursor-pointer font-medium text-slate-800 dark:text-slate-200">
          Ver tabla de puntuación
        </summary>
        <ul class="mt-3 space-y-1.5 text-slate-700 dark:text-slate-300">
          <li v-for="rule in visibleRules" :key="rule.id || rule.name">
            {{ rule.name }} — {{ rule.points }} pts
          </li>
        </ul>
      </details>

      <div
        v-if="standings.length === 0"
        class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
      >
        Todavía no hay resultados computados para este ranking.
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
                Pos.
              </th>
              <th
                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Jugador
              </th>
              <th
                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Puntos
              </th>
              <th
                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Competencias
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
            <tr
              v-for="row in standings"
              :key="row.player_id"
              class="hover:bg-slate-50 dark:hover:bg-slate-800"
            >
              <td class="px-4 py-3 text-sm">
                <span
                  class="inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold"
                  :class="rankingPositionBadgeClasses(row.position)"
                >
                  {{ formatRankingPosition(row.position) }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">
                {{ row.display_name }}
              </td>
              <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
                {{ row.points }}
              </td>
              <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
                {{ row.events_count }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>
