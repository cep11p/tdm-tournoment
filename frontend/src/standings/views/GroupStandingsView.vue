<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import BracketService from '../../brackets/services/BracketService'
import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import { usePermissions } from '../../composables/usePermissions'
import { useTransientMessage } from '../../composables/useTransientMessage'
import CompetitionService from '../../competitions/services/CompetitionService'
import { isTeamCompetition, participantPlural } from '../../shared/constants/competitionType'
import GroupManualTiebreakPanel from '../components/GroupManualTiebreakPanel.vue'
import GroupStandingsTable from '../components/GroupStandingsTable.vue'
import StandingService from '../services/StandingService'
import { buildGroupStandingRows } from '../utils/buildGroupStandingRows'
import {
  getQualificationBadgeClasses,
  getQualificationIcon,
  getQualificationLabel,
  getQualificationRowClasses,
  qualificationBadgeLayoutClasses,
} from '../utils/resolveGroupQualification'

const route = useRoute()
const { can } = usePermissions()
const canManageGroups = computed(() => can('groups.manage'))

const groupId = computed(() => route.params.id)
const competitionId = computed(() => route.query.competitionId || '')
const groupName = computed(() => route.query.groupName || `Grupo #${groupId.value}`)
const competition = ref(null)
const hasBracket = ref(false)

const qualifiedPerGroup = computed(() => competition.value?.qualified_per_group ?? 2)

const isTeam = computed(() => isTeamCompetition(competition.value))

const participantsCountLabel = computed(() => {
  const count = standings.value.length
  const label = participantPlural(competition.value)

  return `${count} ${label}`
})

const completedScheduleCount = computed(() => {
  if (isTeam.value) {
    const finished = Number(standingsMeta.value.finished_team_ties_count ?? completedGamesCount.value)

    return finished
  }

  return completedMatches.value
})

const standings = ref([])
const standingsMeta = ref({})
const isLoading = ref(false)
const errorMessage = ref('')
const manualTiebreakNotice = useTransientMessage()

const pendingManualTiebreakGroups = computed(() => standingsMeta.value.manual_tiebreak_groups ?? [])

const staleManualTiebreaks = computed(() => standingsMeta.value.stale_manual_tiebreaks ?? [])

const standingsAreProvisional = computed(() => Boolean(standingsMeta.value.standings_are_provisional))

const completedGamesCount = computed(() => Number(standingsMeta.value.completed_games_count ?? 0))

const requiresManualTiebreak = computed(
  () => !standingsAreProvisional.value && Boolean(standingsMeta.value.requires_manual_tiebreak),
)

const standingRows = computed(() =>
  buildGroupStandingRows({
    standings: standings.value,
    meta: standingsMeta.value,
    qualifiedPerGroup: qualifiedPerGroup.value,
  }),
)

const provisionalMessage = computed(() => {
  if (!standingsAreProvisional.value) {
    return null
  }

  if (completedGamesCount.value === 0) {
    return isTeam.value
      ? 'Todavía no hay enfrentamientos completados en este grupo. El orden actual es provisorio.'
      : 'Todavía no hay partidos completados en este grupo. El orden actual es provisorio.'
  }

  return isTeam.value
    ? 'Aún quedan enfrentamientos pendientes. El desempate se evaluará cuando finalicen todos los enfrentamientos del grupo.'
    : 'Aún quedan partidos pendientes. El desempate se evaluará cuando finalicen todos los partidos del grupo.'
})

const tiebreakGroupKey = (tiebreakGroup) =>
  [...(tiebreakGroup.entry_ids ?? tiebreakGroup.player_ids ?? [])]
    .sort((left, right) => left - right)
    .join('-')

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

const rowClasses = (row) =>
  getQualificationRowClasses(row.qualification?.kind, {
    isInactive: (row.groupPlayerStatus ?? 'active') !== 'active',
  })

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

const qualificationBadgeClasses = (standing) =>
  getQualificationBadgeClasses(standing.qualification?.kind)

const qualificationLabel = (standing) => getQualificationLabel(standing.qualification?.kind)

const qualificationIcon = (standing) => getQualificationIcon(standing.qualification?.kind)

const formatSignedDifference = (value) => {
  const numeric = Number(value ?? 0)

  if (numeric > 0) {
    return `+${numeric}`
  }

  return String(numeric)
}

const standingRecordLabel = (row) => `${row.won}-${row.lost}`

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
  await loadStandings()
  manualTiebreakNotice.show('Desempate manual guardado correctamente.')
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
      v-else-if="standingRows.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      Este grupo todavía no tiene posiciones para mostrar.
    </div>

    <div v-else class="space-y-4">
      <p v-if="manualTiebreakNotice.message" class="text-sm text-emerald-700 dark:text-emerald-300">
        {{ manualTiebreakNotice.message }}
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

      <template v-if="canManageGroups">
        <GroupManualTiebreakPanel
          v-for="tiebreakGroup in pendingManualTiebreakGroups"
          :key="tiebreakGroupKey(tiebreakGroup)"
          :group-id="groupId"
          :tiebreak-group="tiebreakGroup"
          :is-team="isTeam"
          :disabled="hasBracket"
          @saved="handleManualTiebreakSaved"
        />
      </template>

      <div
        v-if="!standingsAreProvisional && staleManualTiebreaks.length > 0"
        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-300"
      >
        Hay desempates manuales guardados que ya no aplican por cambios en los resultados.
      </div>

      <div class="overflow-hidden rounded-md border border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-900">
        <div class="px-4 py-3">
          <p class="font-semibold text-slate-900 dark:text-slate-100">{{ groupName }}</p>
          <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            {{ participantsCountLabel }} · clasifican {{ qualifiedPerGroup }}<template v-if="completedScheduleCount !== '-'"> · {{ completedScheduleCount }} finalizados</template>
          </p>
        </div>

      <div v-if="isTeam" class="space-y-3 border-t border-slate-200 px-4 py-3 md:hidden dark:border-slate-700">
        <article
          v-for="row in standingRows"
          :key="`team-card-${row.competitionEntryId}`"
          class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
          :class="[
            rowClasses(row),
            row.position === Number(qualifiedPerGroup) + 1
              ? '!mt-4 !border-t-2 !border-t-slate-400 dark:!border-t-slate-500'
              : '',
          ]"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span
                  class="inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold"
                  :class="positionBadgeClasses(row.position)"
                >
                  {{ row.position }}°
                </span>
                <p class="font-medium text-slate-900 dark:text-slate-100">
                  {{ row.displayName }}
                </p>
              </div>
              <p v-if="row.membersLabel" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ row.membersLabel }}
              </p>
            </div>

            <div class="text-right">
              <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ standingRecordLabel(row) }}
              </p>
              <span
                class="mt-1"
                :class="[qualificationBadgeLayoutClasses, qualificationBadgeClasses(row)]"
              >
                <span v-if="qualificationIcon(row)" aria-hidden="true">
                  {{ qualificationIcon(row) }}
                </span>
                {{ qualificationLabel(row) }}
              </span>
            </div>
          </div>

          <details class="mt-3 text-sm text-slate-600 dark:text-slate-300">
            <summary class="cursor-pointer font-medium text-slate-700 dark:text-slate-200">
              Ver detalle
            </summary>
            <dl class="mt-2 grid grid-cols-2 gap-2 text-xs">
              <div>
                <dt>Enfrentamientos</dt>
                <dd class="font-medium text-slate-900 dark:text-slate-100">{{ row.played }}</dd>
              </div>
              <div>
                <dt>Partidos</dt>
                <dd class="font-medium text-slate-900 dark:text-slate-100">
                  {{ formatSignedDifference(row.rubberDifference) }}
                </dd>
              </div>
              <div>
                <dt>Sets</dt>
                <dd class="font-medium text-slate-900 dark:text-slate-100">
                  {{ formatSignedDifference(row.setDifference) }}
                </dd>
              </div>
              <div>
                <dt>Puntos</dt>
                <dd class="font-medium text-slate-900 dark:text-slate-100">
                  {{ formatSignedDifference(row.pointDifference) }}
                </dd>
              </div>
            </dl>
          </details>
        </article>
      </div>

      <GroupStandingsTable
        :rows="standingRows"
        :is-team="isTeam"
        embedded
        :qualifier-cutoff="qualifiedPerGroup"
        :class="['border-t border-slate-200 dark:border-slate-700', isTeam ? 'hidden md:block' : '']"
      >
        <template #extra-headers>
          <template v-if="isTeam">
            <th class="hidden px-2 py-2 text-center text-xs font-medium md:table-cell">Dif. partidos</th>
            <th class="hidden px-2 py-2 text-center text-xs font-medium lg:table-cell">Dif. sets</th>
            <th class="hidden px-2 py-2 text-center text-xs font-medium lg:table-cell">Dif. puntos</th>
          </template>
        </template>
        <template #extra-cells="{ row }">
          <template v-if="isTeam">
            <td class="hidden whitespace-nowrap px-2 py-2 text-center text-xs tabular-nums text-slate-600 md:table-cell dark:text-slate-300">
              {{ formatSignedDifference(row.rubberDifference) }}
            </td>
            <td class="hidden whitespace-nowrap px-2 py-2 text-center text-xs tabular-nums text-slate-600 lg:table-cell dark:text-slate-300">
              {{ formatSignedDifference(row.setDifference) }}
            </td>
            <td class="hidden whitespace-nowrap px-2 py-2 text-center text-xs tabular-nums text-slate-600 lg:table-cell dark:text-slate-300">
              {{ formatSignedDifference(row.pointDifference) }}
            </td>
          </template>
        </template>
      </GroupStandingsTable>
      </div>
    </div>
  </section>
</template>
