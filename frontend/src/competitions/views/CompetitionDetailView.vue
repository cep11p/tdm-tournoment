<script setup>
import {
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
import GenerateRandomGroupsModal from '../../groups/components/GenerateRandomGroupsModal.vue'
import { buildRandomGroupsSuccessMessage } from '../../groups/utils/buildRandomGroupsSuccessMessage'
import StandingService from '../../standings/services/StandingService'
import { buildBracketGenerationPreview } from '../utils/buildBracketGenerationPreview'
import { buildGroupPhaseAlert } from '../utils/buildGroupPhaseAlert'
import {
  competitionHasGroupStage,
  getCompetitionFormatLabel,
} from '../constants/competitionFormats'
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
const randomGroupsSuccessMessage = ref('')
const showGenerateRandomGroupsModal = ref(false)

const competitionId = computed(() => route.params.id)

const fallbackBackRoute = computed(() =>
  competition.value?.tournament_id ? `/tournaments/${competition.value.tournament_id}/competitions` : '/tournaments',
)

const formatCount = (value) => (value === null || value === undefined ? '-' : value)

const playerCount = computed(() =>
  registrations.value === null ? '-' : registrations.value.length,
)

const registeredCount = computed(() => registrations.value?.length ?? 0)

const hasExistingGroups = computed(() => (groups.value?.length ?? 0) > 0)

const hasGroupStage = computed(() => competitionHasGroupStage(competition.value))

const isKnockoutDirect = computed(() => !hasGroupStage.value)

const formatLabel = computed(() => getCompetitionFormatLabel(competition.value))

const isCompetitionCompleted = computed(() => statusSummary.value?.code === 'completed')

const canGenerateRandomGroups = computed(
  () =>
    hasGroupStage.value &&
    registeredCount.value >= 2 &&
    !hasExistingGroups.value &&
    !isCompetitionCompleted.value &&
    groups.value !== null,
)

const canGenerateBracket = computed(
  () =>
    isKnockoutDirect.value &&
    !hasBracket.value &&
    !isCompetitionCompleted.value &&
    registeredCount.value >= 2,
)

const needsMoreRegistrationsForBracket = computed(
  () =>
    isKnockoutDirect.value &&
    !hasBracket.value &&
    !isCompetitionCompleted.value &&
    registeredCount.value < 2 &&
    registrations.value !== null,
)

const groupCount = computed(() => (groups.value === null ? '-' : groups.value.length))

const gameCount = computed(() => (games.value === null ? '-' : games.value.length))

const finishedGameCount = computed(() => {
  if (games.value === null) {
    return '-'
  }

  return games.value.filter((game) => game.status === 'finished').length
})

const bracketGames = computed(() => {
  if (!games.value) {
    return []
  }

  return games.value.filter((game) => game.bracket_id)
})

const hasBracket = computed(() => Boolean(bracket.value?.id))

const qualifiedPerGroup = computed(() => competition.value?.qualified_per_group ?? 2)

const bracketGenerationPreview = computed(() => {
  if (!hasGroupStage.value || hasBracket.value || !competition.value) {
    return null
  }

  return buildBracketGenerationPreview({
    qualifiedPerGroup: qualifiedPerGroup.value,
    groupCount: groups.value === null ? null : groups.value.length,
  })
})

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

const actionLinks = computed(() => {
  const links = [
    {
      to: `/competitions/${competitionId.value}/registrations`,
      label: 'Administrar inscripciones',
      description: 'Gestionar jugadores inscriptos',
      icon: UserGroupIcon,
    },
  ]

  links.push({
    to: `/competitions/${competitionId.value}/games`,
    label: 'Ver partidos',
    description: 'Consultar resultados y estado',
    icon: ViewColumnsIcon,
  })

  links.push({
    to: bracketRoute.value,
    label: hasBracket.value ? 'Ver llave eliminatoria' : 'Generar bracket',
    description: hasBracket.value
      ? 'Consultar rondas y partidos eliminatorios'
      : 'Crear la llave eliminatoria',
    icon: TrophyIcon,
  })

  return links
})

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

    const shouldLoadGroupStandings =
      competitionHasGroupStage(competitionData) && groupsData?.length > 0

    if (shouldLoadGroupStandings) {
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

const handleRandomGroupsSaved = async (result) => {
  showGenerateRandomGroupsModal.value = false
  randomGroupsSuccessMessage.value = buildRandomGroupsSuccessMessage(result)
  await loadCompetitionSummary()
}

const openGenerateRandomGroupsModal = () => {
  randomGroupsSuccessMessage.value = ''
  showGenerateRandomGroupsModal.value = true
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
      <div>
        <p class="mb-3 text-sm font-medium text-slate-700 dark:text-slate-200">Acciones principales</p>

        <div v-if="canGenerateRandomGroups" class="mb-3 flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
            @click="openGenerateRandomGroupsModal"
          >
            Generar grupos
          </button>
        </div>

        <p
          v-if="randomGroupsSuccessMessage"
          class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
        >
          {{ randomGroupsSuccessMessage }}
        </p>

        <p
          v-if="isKnockoutDirect"
          class="mb-3 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100"
        >
          Esta competencia es de eliminación directa. Los jugadores inscriptos pasan directamente a la llave.
        </p>

        <p
          v-if="needsMoreRegistrationsForBracket"
          class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
        >
          Necesitás al menos 2 jugadores inscriptos para generar la llave.
        </p>

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

          <div v-if="hasGroupStage">
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

      <details
        v-if="hasGroupStage && groups !== null && groups.length > 0"
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <summary class="cursor-pointer font-bold text-slate-700 dark:text-slate-200">
          Fase de grupos
        </summary>

        <p
          class="mt-4 rounded-md px-3 py-2 text-xs font-medium"
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
      </details>

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
            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ formatLabel }}</p>
          </div>

          <div class="space-y-1 border-t border-slate-200 pt-3 dark:border-slate-700 sm:col-span-2 lg:col-span-4">
            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Formato de partidos</p>
            <p v-if="hasGroupStage" class="text-sm text-slate-600 dark:text-slate-400">
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
        <p class="font-medium text-slate-700 dark:text-slate-200">Llave eliminatoria</p>

        <template v-if="!hasBracket">
          <p class="mt-3 text-slate-600 dark:text-slate-300">
            Todavía no se generó la llave eliminatoria.
          </p>

          <p
            v-if="isKnockoutDirect"
            class="mt-2 text-sm text-slate-600 dark:text-slate-400"
          >
            La llave se generará con los {{ registeredCount }} jugador{{ registeredCount === 1 ? '' : 'es' }}
            inscripto{{ registeredCount === 1 ? '' : 's' }}.
          </p>

          <div
            v-if="bracketGenerationPreview"
            class="mt-3 space-y-2 rounded-md border border-sky-200 bg-sky-50/60 p-3 dark:border-sky-900 dark:bg-sky-950/20"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="font-medium text-slate-800 dark:text-slate-100">
                {{ bracketGenerationPreview.title }}
              </p>
              <span
                v-if="bracketGenerationPreview.badge"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="
                  bracketGenerationPreview.hasQualifyingRound
                    ? 'bg-violet-100 text-violet-800 dark:bg-violet-900/60 dark:text-violet-200'
                    : 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200'
                "
              >
                {{ bracketGenerationPreview.badge }}
              </span>
            </div>

            <p
              v-for="(line, index) in bracketGenerationPreview.introLines"
              :key="`intro-${index}`"
              class="text-slate-600 dark:text-slate-300"
            >
              {{ line }}
            </p>

            <template v-if="bracketGenerationPreview.statsLines.length > 0">
              <p
                v-for="(line, index) in bracketGenerationPreview.statsLines"
                :key="`stats-${index}`"
                class="text-slate-700 dark:text-slate-200"
              >
                {{ line }}
              </p>
              <ul
                v-if="bracketGenerationPreview.detailLines.length > 0"
                class="list-inside list-disc space-y-1 text-slate-600 dark:text-slate-300"
              >
                <li
                  v-for="(line, index) in bracketGenerationPreview.detailLines"
                  :key="`detail-${index}`"
                >
                  {{ line }}
                </li>
              </ul>
            </template>

            <p
              v-for="(warning, index) in bracketGenerationPreview.warnings"
              :key="`warning-${index}`"
              class="rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
            >
              {{ warning }}
            </p>
          </div>

          <RouterLink
            v-if="canGenerateBracket || hasGroupStage"
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

            <div v-if="hasGroupStage && bracket.qualifiers_per_group > 0">
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

      <GenerateRandomGroupsModal
        v-if="hasGroupStage"
        :show="showGenerateRandomGroupsModal"
        :competition-id="competitionId"
        :registered-count="registeredCount"
        :has-existing-groups="hasExistingGroups"
        :is-competition-completed="isCompetitionCompleted"
        @close="showGenerateRandomGroupsModal = false"
        @saved="handleRandomGroupsSaved"
      />
    </template>
  </section>
</template>
