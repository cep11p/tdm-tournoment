<script setup>
import {
  RectangleGroupIcon,
  TrophyIcon,
  UserGroupIcon,
  ViewColumnsIcon,
} from '@heroicons/vue/24/outline'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import BracketService from '../../brackets/services/BracketService'
import GameService from '../../games/services/GameService'
import GroupService from '../../groups/services/GroupService'
import RegistrationService from '../../registrations/services/RegistrationService'
import BulkPlayerRegistrationModal from '../../registrations/components/BulkPlayerRegistrationModal.vue'
import StandingService from '../../standings/services/StandingService'
import { buildGroupPhaseAlert } from '../utils/buildGroupPhaseAlert'
import CompetitionService from '../services/CompetitionService'

const route = useRoute()

const competition = ref(null)
const bracket = ref(null)
const registrations = ref(null)
const groups = ref(null)
const games = ref(null)
const groupStandingsByGroupId = ref({})
const groupStandingsMetaByGroupId = ref({})

const isLoading = ref(false)
const errorMessage = ref('')
const showBulkRegistrationModal = ref(false)

const competitionId = computed(() => route.params.id)

const qualifiedPerGroup = computed(() => competition.value?.qualified_per_group ?? 2)

const fallbackBackRoute = computed(() =>
  competition.value?.tournament_id ? `/tournaments/${competition.value.tournament_id}/competitions` : '/tournaments',
)

const formatCount = (value) => (value === null || value === undefined ? '-' : value)

const playerCount = computed(() =>
  registrations.value === null ? '-' : registrations.value.length,
)

const registeredPlayerIds = computed(() =>
  (registrations.value ?? []).map((registration) => registration.player?.id).filter(Boolean),
)

const groupCount = computed(() => (groups.value === null ? '-' : groups.value.length))

const gameCount = computed(() => (games.value === null ? '-' : games.value.length))

const finishedGameCount = computed(() => {
  if (games.value === null) {
    return '-'
  }

  return games.value.filter((game) => game.status === 'finished').length
})

const groupGames = computed(() => {
  if (!games.value) {
    return []
  }

  return games.value.filter((game) => game.group_id)
})

const bracketGames = computed(() => {
  if (!games.value) {
    return []
  }

  return games.value.filter((game) => game.bracket_id)
})

const hasBracket = computed(() => Boolean(bracket.value?.id))

const bracketGameCount = computed(() => bracket.value?.games?.length ?? bracketGames.value.length)

const bracketStatus = computed(() => {
  const bracketGameList = bracket.value?.games?.length ? bracket.value.games : bracketGames.value

  if (!hasBracket.value || bracketGameList.length === 0) {
    return null
  }

  if (bracketGameList.every((game) => game.status === 'finished')) {
    return 'Completo'
  }

  if (bracketGameList.some((game) => game.status === 'in_progress' || game.status === 'finished')) {
    return 'En curso'
  }

  return 'Pendiente'
})

const bracketRoute = computed(() => `/competitions/${competitionId.value}/bracket`)

const resultSummary = computed(() => competition.value?.result_summary ?? null)

const statusSummary = computed(() => competition.value?.status_summary ?? null)

const statusBadgeClasses = (code) => {
  switch (code) {
    case 'no_groups':
      return 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200'
    case 'group_stage_pending':
      return 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'
    case 'group_stage_in_progress':
      return 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200'
    case 'ready_for_bracket':
      return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
    case 'knockout_in_progress':
      return 'bg-violet-100 text-violet-800 dark:bg-violet-900/60 dark:text-violet-200'
    case 'completed':
      return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
    default:
      return 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200'
  }
}

const statusActionLink = computed(() => {
  const code = statusSummary.value?.code

  if (!code) {
    return null
  }

  switch (code) {
    case 'no_groups':
    case 'group_stage_pending':
      return {
        to: `/competitions/${competitionId.value}/groups`,
        label: 'Gestionar grupos',
      }
    case 'group_stage_in_progress':
      return {
        to: `/competitions/${competitionId.value}/groups`,
        label: 'Ver grupos',
      }
    case 'ready_for_bracket':
      return {
        to: bracketRoute.value,
        label: hasBracket.value ? 'Ver llave' : 'Generar llave',
      }
    case 'knockout_in_progress':
    case 'completed':
      return {
        to: bracketRoute.value,
        label: 'Ver llave',
      }
    default:
      return null
  }
})

const qualifiersByGroup = computed(() => {
  if (!groups.value?.length) {
    return []
  }

  return groups.value.map((group) => {
    const standings = groupStandingsByGroupId.value[group.id]

    if (!standings?.length) {
      return {
        group,
        qualifiers: null,
      }
    }

    const eligibleQualifiers = standings
      .filter((standing) => standing.eligible_for_qualification !== false)
      .slice(0, qualifiedPerGroup.value)

    return {
      group,
      qualifiers: eligibleQualifiers.map((standing, index) => ({
        ...standing,
        position: index + 1,
      })),
    }
  })
})

const groupPhaseSummaries = computed(() =>
  (groups.value ?? []).map((group) =>
    buildGroupPhaseAlert({
      group,
      standings: groupStandingsByGroupId.value[group.id] ?? [],
      meta: groupStandingsMetaByGroupId.value[group.id] ?? {},
      games: games.value?.filter((game) => Number(game.group_id) === Number(group.id)) ?? [],
    }),
  ),
)

const groupsNeedingAttention = computed(() =>
  groupPhaseSummaries.value.filter((summary) => summary.needsAttention),
)

const groupDetailRoute = (group) => ({
  path: `/groups/${group.id}`,
  query: {
    competitionId: competitionId.value,
    groupName: group.name,
  },
})

const groupStandingsRoute = (group) => ({
  path: `/groups/${group.id}/standings`,
  query: {
    competitionId: competitionId.value,
    groupName: group.name,
  },
})

const groupPhasePrimaryBadgeClasses = (type) => {
  switch (type) {
    case 'warning':
      return 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'
    case 'info':
      return 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200'
    case 'success':
      return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
    case 'muted':
      return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
    default:
      return 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200'
  }
}

const groupPhaseAlertChipClasses = (type) => {
  switch (type) {
    case 'warning':
      return 'bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-100 dark:ring-amber-800'
    case 'info':
      return 'bg-sky-50 text-sky-900 ring-1 ring-sky-200 dark:bg-sky-950/30 dark:text-sky-100 dark:ring-sky-800'
    case 'muted':
      return 'bg-slate-50 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700'
    default:
      return 'bg-slate-50 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700'
  }
}

const groupPhaseCardClasses = (summary) => {
  if (summary.primaryType === 'warning') {
    return 'border-amber-200 bg-amber-50/30 dark:border-amber-900 dark:bg-amber-950/10'
  }

  if (summary.primaryType === 'info' && summary.needsAttention) {
    return 'border-sky-200 bg-sky-50/30 dark:border-sky-900 dark:bg-sky-950/10'
  }

  if (summary.primaryType === 'success') {
    return 'border-emerald-200 bg-emerald-50/30 dark:border-emerald-900 dark:bg-emerald-950/10'
  }

  return 'border-slate-200 bg-slate-50/40 dark:border-slate-700 dark:bg-slate-900/40'
}

const hasQualifiersData = computed(() =>
  qualifiersByGroup.value.some((entry) => entry.qualifiers?.length > 0),
)

const groupPhaseGamesForQualifiers = computed(() => {
  if (!games.value) {
    return []
  }

  return groupGames.value.length > 0 ? groupGames.value : games.value
})

const isGroupPhaseComplete = computed(() => {
  const phaseGames = groupPhaseGamesForQualifiers.value

  if (phaseGames.length === 0) {
    return false
  }

  return phaseGames.every((game) => game.status === 'finished')
})

const qualifiersSectionTitle = computed(() =>
  isGroupPhaseComplete.value ? 'Clasificados' : 'Posiciones provisionales',
)

const qualifiersSectionMessage = computed(() =>
  isGroupPhaseComplete.value
    ? 'Clasificación definida'
    : 'Los clasificados se definirán cuando finalice la fase de grupos.',
)

const qualifiersStatusBadgeClasses = computed(() =>
  isGroupPhaseComplete.value
    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
    : 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200',
)

const qualifiersStatusBadgeLabel = computed(() =>
  isGroupPhaseComplete.value ? 'Definitivo' : 'Provisional',
)

const qualifierItemClasses = computed(() =>
  isGroupPhaseComplete.value
    ? 'border-emerald-200 bg-emerald-50/40 dark:border-emerald-900 dark:bg-emerald-950/20'
    : 'border-amber-200 bg-amber-50/40 dark:border-amber-900 dark:bg-amber-950/20',
)

const positionBadgeClasses = (position) => {
  if (position === 1) {
    return 'bg-amber-100 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-900/50 dark:text-amber-200 dark:ring-amber-800'
  }

  if (position === 2) {
    return 'bg-slate-200 text-slate-800 ring-1 ring-slate-300 dark:bg-slate-600 dark:text-slate-100 dark:ring-slate-500'
  }

  return 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'
}

const actionLinks = computed(() => [
  {
    to: `/competitions/${competitionId.value}/registrations`,
    label: 'Administrar inscripciones',
    description: 'Gestionar jugadores inscriptos',
    icon: UserGroupIcon,
  },
  {
    to: `/competitions/${competitionId.value}/groups`,
    label: 'Administrar grupos',
    description: 'Crear grupos y asignar jugadores',
    icon: RectangleGroupIcon,
  },
  {
    to: `/competitions/${competitionId.value}/games`,
    label: 'Ver partidos',
    description: 'Consultar resultados y estado',
    icon: ViewColumnsIcon,
  },
  {
    to: bracketRoute.value,
    label: hasBracket.value ? 'Ver llave eliminatoria' : 'Generar bracket',
    description: hasBracket.value
      ? 'Consultar rondas y partidos eliminatorios'
      : 'Crear la llave eliminatoria',
    icon: TrophyIcon,
  },
])

const loadCompetitionSummary = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [competitionData, registrationsData, groupsData, gamesData, bracketData] = await Promise.all([
      CompetitionService.show(competitionId.value),
      RegistrationService.listByCompetition(competitionId.value).catch(() => null),
      GroupService.listByCompetition(competitionId.value).catch(() => null),
      GameService.listByCompetition(competitionId.value).catch(() => null),
      BracketService.show(competitionId.value).catch(() => null),
    ])

    competition.value = competitionData
    bracket.value = bracketData
    registrations.value = registrationsData
    groups.value = groupsData
    games.value = gamesData

    if (groupsData?.length > 0) {
      const standingsEntries = await Promise.all(
        groupsData.map(async (group) => {
          try {
            const { standings, meta } = await StandingService.listByGroup(group.id)
            return [group.id, { standings, meta }]
          } catch {
            return [group.id, null]
          }
        }),
      )

      const standingsByGroupId = {}
      const metaByGroupId = {}

      for (const [groupId, payload] of standingsEntries) {
        if (payload) {
          standingsByGroupId[groupId] = payload.standings
          metaByGroupId[groupId] = payload.meta
        }
      }

      groupStandingsByGroupId.value = standingsByGroupId
      groupStandingsMetaByGroupId.value = metaByGroupId
    } else {
      groupStandingsByGroupId.value = {}
      groupStandingsMetaByGroupId.value = {}
    }
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'No se pudo cargar la competencia.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadCompetitionSummary)

const handleBulkRegistrationSaved = async () => {
  showBulkRegistrationModal.value = false
  await loadCompetitionSummary()
}
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: competition?.tournament_id,
        competitionId: competition?.id || competitionId,
        competitionName: competition?.name,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ competition?.name || `Competencia #${competitionId}` }}
      </h1>
      <AppBackButton :fallback-to="fallbackBackRoute" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando competencia...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <template v-else-if="competition">
      <div
        class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm dark:border-slate-700 dark:bg-slate-900/60"
      >
        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Competencia</p>
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ competition.name }}</p>

        <p class="mt-4 font-medium text-slate-700 dark:text-slate-200">Resumen</p>

        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Jugadores</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ formatCount(playerCount) }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Grupos</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ formatCount(groupCount) }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Partidos</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ formatCount(gameCount) }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Finalizados</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ formatCount(finishedGameCount) }}
            </dd>
          </div>
        </dl>

        <p
          v-if="games !== null && games.length === 0"
          class="mt-3 text-sm text-slate-600 dark:text-slate-300"
        >
          No hay partidos generados
        </p>
      </div>

      <div
        v-if="statusSummary"
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="space-y-2">
            <p class="font-medium text-slate-700 dark:text-slate-200">Estado de la competencia</p>

            <span
              class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
              :class="statusBadgeClasses(statusSummary.code)"
            >
              {{ statusSummary.label }}
            </span>

            <p class="text-slate-600 dark:text-slate-300">{{ statusSummary.description }}</p>

            <p class="text-slate-700 dark:text-slate-200">
              Próxima acción:
              <span class="font-medium text-slate-900 dark:text-slate-100">{{ statusSummary.next_action }}</span>
            </p>
          </div>

          <RouterLink
            v-if="statusActionLink"
            :to="statusActionLink.to"
            class="inline-flex shrink-0 rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          >
            {{ statusActionLink.label }}
          </RouterLink>
        </div>
      </div>

      <div
        v-if="groups !== null && groups.length > 0"
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Estado de la fase de grupos</p>

        <p
          class="mt-2 rounded-md px-3 py-2 text-xs font-medium"
          :class="
            groupsNeedingAttention.length > 0
              ? 'bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-100'
              : 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100'
          "
        >
          {{
            groupsNeedingAttention.length > 0
              ? `${groupsNeedingAttention.length} grupo${groupsNeedingAttention.length === 1 ? '' : 's'} requieren atención`
              : 'Fase de grupos en orden'
          }}
        </p>

        <div class="mt-3 space-y-3">
          <article
            v-for="summary in groupPhaseSummaries"
            :key="summary.group.id"
            class="rounded-md border p-3"
            :class="groupPhaseCardClasses(summary)"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ summary.group.name }}</p>

              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="groupPhasePrimaryBadgeClasses(summary.primaryType)"
              >
                {{ summary.primaryLabel }}
              </span>
            </div>

            <div v-if="summary.alerts.length > 0" class="mt-2 flex flex-wrap gap-2">
              <span
                v-for="(alert, alertIndex) in summary.alerts"
                :key="`${summary.group.id}-alert-${alertIndex}`"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="groupPhaseAlertChipClasses(alert.type)"
              >
                {{ alert.label }}
              </span>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
              <RouterLink
                :to="groupStandingsRoute(summary.group)"
                class="inline-flex rounded-md px-3 py-1.5 text-xs font-medium"
                :class="
                  summary.highlightLink === 'standings'
                    ? 'bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'
                    : 'border border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800'
                "
              >
                Ver posiciones
              </RouterLink>

              <RouterLink
                :to="groupDetailRoute(summary.group)"
                class="inline-flex rounded-md px-3 py-1.5 text-xs font-medium"
                :class="
                  summary.highlightLink === 'group'
                    ? 'bg-slate-900 text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'
                    : 'border border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800'
                "
              >
                Ver grupo
              </RouterLink>
            </div>
          </article>
        </div>
      </div>

      <div
        v-if="resultSummary"
        class="rounded-md border border-emerald-200 bg-gradient-to-b from-emerald-50 to-white p-4 text-sm dark:border-emerald-900 dark:from-emerald-950/30 dark:to-slate-900"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
              Resultado final
            </p>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
              La competencia ya tiene campeón y subcampeón definidos.
            </p>
          </div>

          <RouterLink
            :to="bracketRoute"
            class="inline-flex shrink-0 rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          >
            Ver llave
          </RouterLink>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <article
            class="rounded-md border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/40"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800 dark:text-emerald-300">
              🏆 Campeón
            </p>
            <p class="mt-2 text-xl font-bold text-slate-900 dark:text-slate-100">
              {{ resultSummary.champion.name }}
            </p>
          </article>

          <article
            class="rounded-md border border-slate-300 bg-slate-50 p-4 dark:border-slate-600 dark:bg-slate-800/60"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
              Subcampeón
            </p>
            <p class="mt-2 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ resultSummary.runner_up.name }}
            </p>
          </article>
        </div>
      </div>

      <details
        class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
      >
        <summary class="cursor-pointer text-sm font-semibold text-slate-900 dark:text-slate-100">
          Configuración de la competencia
        </summary>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">Nombre</p>
            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ competition.name }}</p>
          </div>

          <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">Categoría</p>
            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ competition.category }}</p>
          </div>

          <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">Tipo</p>
            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ competition.type }}</p>
          </div>

          <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">Formato</p>
            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ competition.format }}</p>
          </div>

          <div class="space-y-1 border-t border-slate-200 pt-3 dark:border-slate-700 sm:col-span-2 lg:col-span-4">
            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Formato de partidos</p>
            <p class="text-sm text-slate-600 dark:text-slate-400">
              Grupos: mejor de {{ formatCount(competition.group_stage_best_of) }}
            </p>
            <p class="text-sm text-slate-600 dark:text-slate-400">
              Eliminatorias: mejor de {{ formatCount(competition.knockout_stage_best_of) }}
            </p>
            <p class="text-sm text-slate-600 dark:text-slate-400">
              Semifinal: mejor de {{ formatCount(competition.semifinal_best_of) }}
            </p>
            <p class="text-sm text-slate-600 dark:text-slate-400">
              Final: mejor de {{ formatCount(competition.final_best_of) }}
            </p>
          </div>

          <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">Puntos por set</p>
            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ competition.points_per_set }}</p>
          </div>
        </div>
      </details>

      <div
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-medium text-slate-700 dark:text-slate-200">{{ qualifiersSectionTitle }}</p>

          <span
            v-if="hasQualifiersData"
            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
            :class="qualifiersStatusBadgeClasses"
          >
            <span aria-hidden="true">{{ isGroupPhaseComplete ? '✓' : '⏳' }}</span>
            {{ qualifiersStatusBadgeLabel }}
          </span>
        </div>

        <p
          v-if="hasQualifiersData"
          class="mt-2 text-slate-600 dark:text-slate-300"
        >
          <span aria-hidden="true">{{ isGroupPhaseComplete ? '✓' : '⏳' }}</span>
          {{ qualifiersSectionMessage }}
        </p>

        <p
          v-if="groups !== null && groups.length === 0"
          class="mt-3 text-slate-600 dark:text-slate-300"
        >
          No hay grupos creados
        </p>

        <p
          v-else-if="groups !== null && groups.length > 0 && !hasQualifiersData"
          class="mt-3 text-slate-600 dark:text-slate-300"
        >
          Las posiciones aún no están disponibles
        </p>

        <div v-else-if="hasQualifiersData" class="mt-3 space-y-4">
          <div v-for="entry in qualifiersByGroup" :key="entry.group.id">
            <template v-if="entry.qualifiers?.length">
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ entry.group.name }}</p>

              <ul class="mt-2 space-y-2">
                <li
                  v-for="qualifier in entry.qualifiers"
                  :key="qualifier.player_id"
                  class="flex items-center gap-2 rounded-md border px-3 py-2"
                  :class="qualifierItemClasses"
                >
                  <span
                    class="inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold"
                    :class="positionBadgeClasses(qualifier.position)"
                  >
                    {{ qualifier.position }}°
                  </span>
                  <span class="font-medium text-slate-900 dark:text-slate-100">
                    {{ qualifier.player_name }}
                  </span>
                </li>
              </ul>
            </template>
          </div>
        </div>
      </div>

      <div
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Llave eliminatoria</p>

        <template v-if="!hasBracket">
          <p class="mt-3 text-slate-600 dark:text-slate-300">
            Todavía no se generó la llave eliminatoria.
          </p>

          <RouterLink
            :to="bracketRoute"
            class="mt-4 inline-flex rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          >
            Generar bracket
          </RouterLink>
        </template>

        <template v-else>
          <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Nombre</dt>
              <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.name }}</dd>
            </div>

            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Clasifican por grupo</dt>
              <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                {{ bracket.qualifiers_per_group }}
              </dd>
            </div>

            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Partidos</dt>
              <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracketGameCount }}</dd>
            </div>

            <div v-if="bracketStatus">
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Estado</dt>
              <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracketStatus }}</dd>
            </div>
          </dl>

          <RouterLink
            :to="bracketRoute"
            class="mt-4 inline-flex rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          >
            Ver llave eliminatoria
          </RouterLink>
        </template>
      </div>

      <div>
        <p class="mb-3 text-sm font-medium text-slate-700 dark:text-slate-200">Acciones principales</p>

        <div class="mb-3">
          <button
            type="button"
            class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
            @click="showBulkRegistrationModal = true"
          >
            Inscribir jugadores
          </button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <RouterLink
            v-for="action in actionLinks"
            :key="action.to"
            :to="action.to"
            class="group flex items-start gap-3 rounded-md border border-slate-200 bg-white p-4 text-sm transition hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-slate-600 dark:hover:bg-slate-800/80"
          >
            <component
              :is="action.icon"
              class="mt-0.5 h-6 w-6 shrink-0 text-slate-500 group-hover:text-slate-700 dark:text-slate-400 dark:group-hover:text-slate-200"
            />
            <div>
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ action.label }}</p>
              <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ action.description }}</p>
            </div>
          </RouterLink>
        </div>
      </div>

      <BulkPlayerRegistrationModal
        :show="showBulkRegistrationModal"
        :competition-id="competitionId"
        :registered-player-ids="registeredPlayerIds"
        @close="showBulkRegistrationModal = false"
        @saved="handleBulkRegistrationSaved"
      />
    </template>
  </section>
</template>
