<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import BracketService from '../../brackets/services/BracketService'
import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import CompetitionService from '../../competitions/services/CompetitionService'
import { getGroupPlayerStatusLabel } from '../../groups/constants/groupPlayerStatus'
import GroupManualTiebreakPanel from '../components/GroupManualTiebreakPanel.vue'
import StandingService from '../services/StandingService'

const route = useRoute()

const groupId = computed(() => route.params.id)
const competitionId = computed(() => route.query.competitionId || '')
const groupName = computed(() => route.query.groupName || `Grupo #${groupId.value}`)
const competition = ref(null)
const hasBracket = ref(false)

const qualifiedPerGroup = computed(() => competition.value?.qualified_per_group ?? 2)

const standings = ref([])
const standingsMeta = ref({})
const isLoading = ref(false)
const errorMessage = ref('')
const manualTiebreakSuccessMessage = ref('')

const standingsWithPosition = computed(() =>
  standings.value.map((standing, index) => ({
    ...standing,
    position: index + 1,
  })),
)

const pendingManualTiebreakGroups = computed(() => standingsMeta.value.manual_tiebreak_groups ?? [])

const staleManualTiebreaks = computed(() => standingsMeta.value.stale_manual_tiebreaks ?? [])

const standingsAreProvisional = computed(() => Boolean(standingsMeta.value.standings_are_provisional))

const completedGamesCount = computed(() => Number(standingsMeta.value.completed_games_count ?? 0))

const requiresManualTiebreak = computed(
  () => !standingsAreProvisional.value && Boolean(standingsMeta.value.requires_manual_tiebreak),
)

const provisionalMessage = computed(() => {
  if (!standingsAreProvisional.value) {
    return null
  }

  if (completedGamesCount.value === 0) {
    return 'Todavía no hay partidos completados en este grupo. El orden actual es provisorio.'
  }

  return 'Aún quedan partidos pendientes. El desempate se evaluará cuando finalicen todos los partidos del grupo.'
})

const tiebreakGroupKey = (tiebreakGroup) =>
  [...(tiebreakGroup.player_ids ?? [])].sort((left, right) => left - right).join('-')

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

const isPlayerInactive = (standing) => {
  const status = standing?.group_player_status ?? 'active'
  return status !== 'active'
}

const isQualified = (standing) => {
  if (typeof standing?.eligible_for_qualification === 'boolean') {
    return standing.eligible_for_qualification
  }

  return Number(standing?.position) <= Number(qualifiedPerGroup.value)
}

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
  if (isPlayerInactive(standing)) {
    return 'border-t border-slate-200 bg-slate-50/60 opacity-80 dark:border-slate-700 dark:bg-slate-800/40'
  }

  if (standingsAreProvisional.value) {
    if (isQualified(standing)) {
      return 'border-t border-slate-200 bg-slate-50/50 dark:border-slate-700 dark:bg-slate-900/30'
    }

    return 'border-t border-slate-200 dark:border-slate-700'
  }

  if (isQualified(standing)) {
    return 'border-t border-slate-200 bg-emerald-50/40 dark:border-slate-700 dark:bg-emerald-950/20'
  }

  return 'border-t border-slate-200 bg-red-50/30 dark:border-slate-700 dark:bg-red-950/10'
}

const qualificationBadgeClasses = (standing) => {
  if (standingsAreProvisional.value) {
    if (isQualified(standing)) {
      return 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600'
    }

    return 'bg-slate-50 text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900/60 dark:text-slate-400 dark:ring-slate-700'
  }

  if (isQualified(standing)) {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
  }

  return 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
}

const qualificationLabel = (standing) => {
  const qualified = isQualified(standing)

  if (standingsAreProvisional.value) {
    return qualified ? 'Clasifica provisoriamente' : 'Fuera provisoriamente'
  }

  return qualified ? 'Clasifica' : 'Eliminado'
}

const qualificationIcon = (standing) => {
  if (standingsAreProvisional.value) {
    return ''
  }

  return isQualified(standing) ? '✓' : '✗'
}

const playerStatusBadgeClasses = (status) => {
  if (status === 'withdrawn') {
    return 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'
  }

  if (status === 'disqualified') {
    return 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
  }

  return ''
}

const playerStatusLabel = (standing) => getGroupPlayerStatusLabel(standing?.group_player_status ?? 'active')

const loadStandings = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { standings: groupStandings, meta } = await StandingService.listByGroup(groupId.value)
    standings.value = groupStandings
    standingsMeta.value = meta
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'No se pudo cargar la tabla de posiciones.'
  } finally {
    isLoading.value = false
  }
}

const loadCompetition = async () => {
  if (!competitionId.value) {
    competition.value = null
    hasBracket.value = false
    return
  }

  try {
    competition.value = await CompetitionService.show(competitionId.value)
  } catch {
    competition.value = null
  }

  try {
    const bracket = await BracketService.show(competitionId.value)
    hasBracket.value = Boolean(bracket)
  } catch {
    hasBracket.value = false
  }
}

const handleManualTiebreakSaved = async () => {
  manualTiebreakSuccessMessage.value = 'Desempate manual guardado correctamente.'
  await loadStandings()
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
      <p v-if="manualTiebreakSuccessMessage" class="text-sm text-emerald-700 dark:text-emerald-300">
        {{ manualTiebreakSuccessMessage }}
      </p>

      <div
        v-if="provisionalMessage"
        class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-300"
      >
        {{ provisionalMessage }}
      </div>

      <div
        v-if="requiresManualTiebreak"
        class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
      >
        <p class="font-medium">Empate no resuelto automáticamente.</p>
        <p class="mt-1">Definí el orden manual para continuar con la clasificación.</p>
      </div>

      <GroupManualTiebreakPanel
        v-for="tiebreakGroup in pendingManualTiebreakGroups"
        :key="tiebreakGroupKey(tiebreakGroup)"
        :group-id="groupId"
        :tiebreak-group="tiebreakGroup"
        :disabled="hasBracket"
        @saved="handleManualTiebreakSaved"
      />

      <div
        v-if="!standingsAreProvisional && staleManualTiebreaks.length > 0"
        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-300"
      >
        Hay desempates manuales guardados que ya no aplican por cambios en los resultados.
      </div>

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
                <div class="flex flex-wrap items-center gap-2">
                  <span>{{ standing.player_name }}</span>
                  <span
                    v-if="standing.manual_tiebreak_applied && !standingsAreProvisional"
                    class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/50 dark:text-amber-200"
                  >
                    Desempate manual
                  </span>
                  <span
                    v-if="playerStatusLabel(standing)"
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="playerStatusBadgeClasses(standing.group_player_status)"
                  >
                    {{ playerStatusLabel(standing) }}
                  </span>
                </div>
              </td>
              <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ standing.played }}</td>
              <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ standing.won }}</td>
              <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ standing.lost }}</td>
              <td class="px-3 py-2">
                <span
                  class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="qualificationBadgeClasses(standing)"
                >
                  <span v-if="qualificationIcon(standing)" aria-hidden="true">
                    {{ qualificationIcon(standing) }}
                  </span>
                  {{ qualificationLabel(standing) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>
