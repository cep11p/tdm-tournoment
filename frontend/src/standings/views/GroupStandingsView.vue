<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import CompetitionService from '../../competitions/services/CompetitionService'
import StandingService from '../services/StandingService'

const route = useRoute()

const groupId = computed(() => route.params.id)
const competitionId = computed(() => route.query.competitionId || '')
const groupName = computed(() => route.query.groupName || `Grupo #${groupId.value}`)
const competition = ref(null)

const qualifiedPerGroup = computed(() => competition.value?.qualified_per_group ?? 2)

const standings = ref([])
const isLoading = ref(false)
const errorMessage = ref('')

const standingsWithPosition = computed(() =>
  standings.value.map((standing, index) => ({
    ...standing,
    position: index + 1,
  })),
)

const completedMatches = computed(() => {
  if (standings.value.length === 0) {
    return '-'
  }

  const totalPlayed = standings.value.reduce((sum, standing) => sum + standing.played, 0)

  if (totalPlayed === 0) {
    return 0
  }

  const matches = totalPlayed / 2

  return Number.isInteger(matches) ? matches : '-'
})

const isQualified = (position) => position <= qualifiedPerGroup.value

const positionBadgeClasses = (position) => {
  if (position === 1) {
    return 'bg-amber-100 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-900/50 dark:text-amber-200 dark:ring-amber-800'
  }

  if (position === 2) {
    return 'bg-slate-200 text-slate-800 ring-1 ring-slate-300 dark:bg-slate-600 dark:text-slate-100 dark:ring-slate-500'
  }

  if (position === 3) {
    return 'bg-orange-100 text-orange-900 ring-1 ring-orange-200 dark:bg-orange-900/50 dark:text-orange-200 dark:ring-orange-800'
  }

  return 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'
}

const rowClasses = (standing) => {
  if (isQualified(standing.position)) {
    return 'border-t border-slate-200 bg-emerald-50/40 dark:border-slate-700 dark:bg-emerald-950/20'
  }

  return 'border-t border-slate-200 bg-red-50/30 dark:border-slate-700 dark:bg-red-950/10'
}

const qualificationBadgeClasses = (position) => {
  if (isQualified(position)) {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
  }

  return 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
}

const qualificationLabel = (position) => (isQualified(position) ? 'Clasifica' : 'Eliminado')

const loadStandings = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    standings.value = await StandingService.listByGroup(groupId.value)
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'No se pudo cargar la tabla de posiciones.'
  } finally {
    isLoading.value = false
  }
}

const loadCompetition = async () => {
  if (!competitionId.value) {
    competition.value = null
    return
  }

  try {
    competition.value = await CompetitionService.show(competitionId.value)
  } catch {
    competition.value = null
  }
}

onMounted(async () => {
  await Promise.all([loadStandings(), loadCompetition()])
})
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: competition?.tournament_id,
        competitionId: competitionId || competition?.id,
        competitionName: competition?.name,
        groupId,
        groupName,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ competition?.name ? `${competition.name} - ${groupName} - Posiciones` : `Posiciones - ${groupName}` }}
      </h1>

      <AppBackButton
        :fallback-to="
          competitionId
            ? `/groups/${groupId}?competitionId=${competitionId}&groupName=${encodeURIComponent(groupName)}`
            : `/groups/${groupId}`
        "
      />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando posiciones...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <div
      v-else-if="standingsWithPosition.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      Este grupo todavía no tiene posiciones para mostrar.
    </div>

    <div v-else class="space-y-4">
      <div
        class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm dark:border-slate-700 dark:bg-slate-900/60"
      >
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ groupName }}</p>

        <dl class="mt-3 grid gap-3 sm:grid-cols-3">
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Jugadores</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">{{ standings.length }}</dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Clasifican</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">{{ qualifiedPerGroup }}</dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
              Partidos completados
            </dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">{{ completedMatches }}</dd>
          </div>
        </dl>
      </div>

      <div class="overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-700 dark:bg-slate-800/80 dark:text-slate-200">
            <tr>
              <th class="px-3 py-2 text-left font-medium">Posición</th>
              <th class="px-3 py-2 text-left font-medium">Jugador</th>
              <th class="px-3 py-2 text-left font-medium">PJ</th>
              <th class="px-3 py-2 text-left font-medium">PG</th>
              <th class="px-3 py-2 text-left font-medium">PP</th>
              <th class="px-3 py-2 text-left font-medium">Estado</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="standing in standingsWithPosition"
              :key="standing.player_id"
              :class="rowClasses(standing)"
            >
              <td class="px-3 py-2">
                <span
                  class="inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold"
                  :class="positionBadgeClasses(standing.position)"
                >
                  {{ standing.position }}°
                </span>
              </td>
              <td class="px-3 py-2 font-medium text-slate-900 dark:text-slate-100">
                {{ standing.player_name }}
              </td>
              <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ standing.played }}</td>
              <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ standing.won }}</td>
              <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ standing.lost }}</td>
              <td class="px-3 py-2">
                <span
                  class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="qualificationBadgeClasses(standing.position)"
                >
                  <span aria-hidden="true">{{ isQualified(standing.position) ? '✓' : '✗' }}</span>
                  {{ qualificationLabel(standing.position) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>
