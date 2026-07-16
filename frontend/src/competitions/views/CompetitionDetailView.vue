<script setup>
import {
  ChevronDownIcon,
  Cog6ToothIcon,
  Squares2X2Icon,
  TrophyIcon,
  UserGroupIcon,
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
import RegenerateRandomGroupsModal from '../../groups/components/RegenerateRandomGroupsModal.vue'
import { buildRandomGroupsSuccessMessage } from '../../groups/utils/buildRandomGroupsSuccessMessage'
import { buildRegenerateRandomGroupsSuccessMessage } from '../../groups/utils/buildRegenerateRandomGroupsSuccessMessage'
import StandingService from '../../standings/services/StandingService'
import { buildBracketGenerationPreview } from '../utils/buildBracketGenerationPreview'
import { buildGroupPhaseAlert, summarizeGroupPhaseBracketGate } from '../utils/buildGroupPhaseAlert'
import {
  competitionHasGroupStage,
  getCompetitionFormatLabel,
} from '../constants/competitionFormats'
import CompetitionFormModal from '../components/CompetitionFormModal.vue'
import CompetitionParticipantsModal from '../components/CompetitionParticipantsModal.vue'
import CompetitionService from '../services/CompetitionService'
import {
  isRegistrationsEditable,
  isStructureEditable,
  registrationsLockReason,
  structureLockReason,
} from '../utils/competitionStructure'
import { getCompetitionTypeLabel } from '../../shared/constants/competitionType'

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
const showRegenerateRandomGroupsModal = ref(false)
const showEditCompetitionModal = ref(false)
const showParticipantsModal = ref(false)

const competitionId = computed(() => route.params.id)

const breadcrumbContext = computed(() => ({
  tournamentId: competition.value?.tournament_id,
  tournamentName: competition.value?.tournament?.name,
  competitionId: competition.value?.id || competitionId.value,
  competitionName: competition.value?.name || 'Competencia',
}))

const fallbackBackRoute = computed(() =>
  competition.value?.tournament_id ? `/tournaments/${competition.value.tournament_id}` : '/tournaments',
)

const backButtonLabel = computed(() =>
  competition.value?.tournament_id ? 'Volver al torneo' : 'Volver',
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

const competitionStructureEditable = computed(() => isStructureEditable(competition.value))

const competitionStructureLockReason = computed(() => structureLockReason(competition.value))

const registrationsEditable = computed(() => isRegistrationsEditable(competition.value))

const registrationsLockMessage = computed(() => registrationsLockReason(competition.value))

const canGenerateRandomGroups = computed(
  () =>
    competitionStructureEditable.value &&
    hasGroupStage.value &&
    registeredCount.value >= 2 &&
    !hasExistingGroups.value &&
    !isCompetitionCompleted.value &&
    groups.value !== null,
)

const canRegenerateRandomGroups = computed(
  () =>
    competitionStructureEditable.value &&
    hasGroupStage.value &&
    hasExistingGroups.value &&
    registeredCount.value >= 2 &&
    !isCompetitionCompleted.value &&
    groups.value !== null,
)

const existingGroupsCount = computed(() => groups.value?.length ?? 0)

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

const registrationsRoute = computed(
  () => `/competitions/${competitionId.value}/registrations`,
)

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

const groupPhaseBracketGate = computed(() =>
  summarizeGroupPhaseBracketGate(groupPhaseSummaries.value),
)

const allGroupsReadyForBracket = computed(() => {
  if (!hasGroupStage.value) {
    return true
  }

  return groupPhaseBracketGate.value.allGroupsReadyForBracket
})

const groupPhaseBracketBlockMessage = computed(() => groupPhaseBracketGate.value.blockMessage)

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

const structureAction = computed(() => {
  if (!competition.value || statusSummary.value === null) {
    return null
  }

  const code = statusSummary.value.code

  if (hasBracket.value || code === 'knockout_in_progress' || code === 'completed') {
    return {
      key: 'view-bracket',
      type: 'link',
      to: bracketRoute.value,
      label: 'Ver llave eliminatoria',
      description:
        code === 'completed'
          ? 'Consultar rondas y campeón'
          : 'Consultar rondas y partidos eliminatorios',
      icon: TrophyIcon,
    }
  }

  if (code === 'no_groups') {
    if (!competitionStructureEditable.value) {
      return {
        key: 'generate-groups',
        type: 'disabled',
        label: 'Generar grupos',
        description: competitionStructureLockReason.value,
        icon: Squares2X2Icon,
      }
    }

    const disabled = !canGenerateRandomGroups.value

    return {
      key: 'generate-groups',
      type: disabled ? 'disabled' : 'modal',
      label: 'Generar grupos',
      description: disabled
        ? 'Necesitás al menos 2 jugadores inscriptos'
        : 'Distribuir jugadores inscriptos en grupos',
      icon: Squares2X2Icon,
    }
  }

  if (code === 'group_stage_attention_required') {
    return {
      key: 'generate-bracket-disabled',
      type: 'disabled',
      label: 'Generar llave',
      description:
        groupPhaseBracketBlockMessage.value ??
        'La fase de grupos requiere atención antes de generar la llave.',
      icon: TrophyIcon,
    }
  }

  if (code === 'ready_for_bracket') {
    if (!allGroupsReadyForBracket.value) {
      return {
        key: 'generate-bracket-disabled',
        type: 'disabled',
        label: 'Generar llave',
        description:
          groupPhaseBracketBlockMessage.value ??
          'La fase de grupos requiere atención antes de generar la llave.',
        icon: TrophyIcon,
      }
    }

    return {
      key: 'generate-bracket',
      type: 'link',
      to: bracketRoute.value,
      label: 'Generar llave',
      description: 'Crear la llave eliminatoria',
      icon: TrophyIcon,
    }
  }

  if (code === 'group_stage_in_progress') {
    return {
      key: 'generate-bracket-disabled',
      type: 'disabled',
      label: 'Generar llave',
      description: 'Hay partidos de grupo pendientes. Completalos antes de generar la llave.',
      icon: TrophyIcon,
    }
  }

  if (code === 'group_stage_pending') {
    return {
      key: 'generate-bracket-disabled',
      type: 'disabled',
      label: 'Generar llave',
      description: 'Hay grupos sin partidos generados.',
      icon: TrophyIcon,
    }
  }

  if (code === 'awaiting_registrations') {
    return {
      key: 'generate-bracket-disabled',
      type: 'disabled',
      label: 'Generar llave',
      description: 'Necesitás al menos 2 jugadores inscriptos',
      icon: TrophyIcon,
    }
  }

  return null
})

const groupStructureAction = computed(() =>
  structureAction.value?.key === 'generate-groups' ? structureAction.value : null,
)

const bracketStructureAction = computed(() => {
  const action = structureAction.value

  if (!action) {
    return null
  }

  if (['view-bracket', 'generate-bracket', 'generate-bracket-disabled'].includes(action.key)) {
    return action
  }

  return null
})

const bracketBlockMessage = computed(() => {
  if (hasBracket.value) {
    return null
  }

  if (groupPhaseSummaries.value.some((summary) => summary.pendingGamesCount > 0)) {
    return 'Todavía no se puede generar la llave. Hay partidos de grupo pendientes.'
  }

  if (hasGroupStage.value && !allGroupsReadyForBracket.value && groupPhaseBracketBlockMessage.value) {
    return groupPhaseBracketBlockMessage.value
  }

  const action = bracketStructureAction.value

  if (action?.type === 'disabled') {
    return action.description
  }

  return null
})

const bracketCompactStats = computed(() => {
  const preview = bracketGenerationPreview.value

  if (!preview) {
    return null
  }

  const groupCountNum = groups.value?.length ?? 0
  const totalQualified = groupCountNum > 0 ? groupCountNum * qualifiedPerGroup.value : null
  const bracketSizeLine = preview.statsLines.find((line) => line.includes('llave de'))
  const byesLine = preview.detailLines.find(
    (line) => line.includes('Pase directo') || line.includes('pases directos'),
  )

  let bracketSize = null

  if (bracketSizeLine) {
    const match = bracketSizeLine.match(/llave de (\d+)/)
    bracketSize = match ? Number(match[1]) : null
  }

  let byesCount = null

  if (byesLine) {
    const match = byesLine.match(/(\d+) pase/)
    byesCount = match ? Number(match[1]) : 0
  } else if (bracketSize !== null && totalQualified !== null) {
    byesCount = Math.max(0, bracketSize - totalQualified)
  }

  return {
    qualifiedPerGroup: qualifiedPerGroup.value,
    totalQualified,
    bracketSize,
    byesCount,
    badge: preview.badge,
    hasQualifyingRound: preview.hasQualifyingRound,
    warnings: preview.warnings,
  }
})

const hasBracketGenerationDetails = computed(
  () =>
    Boolean(bracketGenerationPreview.value) &&
    (bracketGenerationPreview.value.introLines.length > 0 ||
      bracketGenerationPreview.value.detailLines.length > 0),
)

const sectionCardClasses =
  'overflow-hidden rounded-md border border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-900'

const sectionInteractiveClasses =
  'w-full cursor-pointer text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/50'

const groupPhaseAccordionSummaryClasses =
  'flex cursor-pointer list-none items-center gap-3 rounded-md p-4 text-sm transition hover:bg-slate-50 dark:hover:bg-slate-800/50 [&::-webkit-details-marker]:hidden'

const groupPhaseAccordionIconContainerClasses =
  'flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 ring-1 ring-slate-200 dark:bg-slate-800/80 dark:ring-slate-600'

const groupPhaseAccordionIconClasses =
  'h-5 w-5 text-slate-600 dark:text-slate-300'

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

const handleRegenerateRandomGroupsSaved = async (result) => {
  showRegenerateRandomGroupsModal.value = false
  randomGroupsSuccessMessage.value = buildRegenerateRandomGroupsSuccessMessage(result)
  await loadCompetitionSummary()
}

const openRegenerateRandomGroupsModal = () => {
  randomGroupsSuccessMessage.value = ''
  showRegenerateRandomGroupsModal.value = true
}

const openParticipantsModal = () => {
  showParticipantsModal.value = true
}

const openEditCompetitionModal = () => {
  showEditCompetitionModal.value = true
}

const handleEditCompetitionClose = () => {
  showEditCompetitionModal.value = false
}

const handleEditCompetitionSaved = async () => {
  showEditCompetitionModal.value = false
  await loadCompetitionSummary()
}
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs :context="breadcrumbContext" />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ competition?.name || `Competencia #${competitionId}` }}
      </h1>
      <div class="flex items-center gap-3">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          @click="openEditCompetitionModal"
        >
          Editar competencia
        </button>
        <RouterLink
          v-if="competition?.tournament_id"
          :to="fallbackBackRoute"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        >
          {{ backButtonLabel }}
        </RouterLink>
        <AppBackButton v-else :fallback-to="fallbackBackRoute" :label="backButtonLabel" />
      </div>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando competencia...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <template v-else-if="competition">
      <p
        v-if="!competitionStructureEditable && competitionStructureLockReason"
        class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
      >
        {{ competitionStructureLockReason }}
      </p>

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

        <p v-if="games !== null && games.length > 0" class="mt-3">
          <RouterLink
            :to="`/competitions/${competitionId}/games`"
            class="text-xs text-slate-500 underline-offset-2 hover:text-slate-700 hover:underline dark:text-slate-400 dark:hover:text-slate-300"
          >
            Ver todos los partidos
          </RouterLink>
        </p>
      </div>

      <button
        v-if="registrations !== null"
        type="button"
        :class="[sectionCardClasses, sectionInteractiveClasses]"
        @click="openParticipantsModal"
      >
        <span :class="[groupPhaseAccordionSummaryClasses, 'pointer-events-none']">
          <span :class="groupPhaseAccordionIconContainerClasses">
            <UserGroupIcon :class="groupPhaseAccordionIconClasses" />
          </span>

          <span class="min-w-0 flex-1 text-left">
            <span class="block font-medium text-slate-900 dark:text-slate-100">Participantes</span>
            <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
              Jugadores inscriptos en esta competencia
            </span>
            <span class="mt-1 block text-xs font-medium text-slate-700 dark:text-slate-300">
              {{ registeredCount }} jugador{{ registeredCount === 1 ? '' : 'es' }}
            </span>
          </span>

          <ChevronDownIcon
            class="h-5 w-5 shrink-0 -rotate-90 text-slate-400"
            aria-hidden="true"
          />
        </span>
      </button>

      <p
        v-if="registrations !== null && !registrationsEditable && registrationsLockMessage"
        class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
      >
        {{ registrationsLockMessage }}
      </p>

      <div
        v-if="hasGroupStage"
        :class="sectionCardClasses"
      >
        <div :class="groupPhaseAccordionSummaryClasses">
          <span :class="groupPhaseAccordionIconContainerClasses">
            <Squares2X2Icon :class="groupPhaseAccordionIconClasses" />
          </span>

          <div class="min-w-0 flex-1">
            <p class="font-medium text-slate-900 dark:text-slate-100">Fase de grupos</p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
              Gestionar grupos, partidos y posiciones
            </p>
            <p
              v-if="typeof groupCount === 'number' && typeof gameCount === 'number'"
              class="mt-1 text-xs text-slate-500 dark:text-slate-400"
            >
              {{ groupCount }} grupo{{ groupCount === 1 ? '' : 's' }}
              · {{ gameCount }} partido{{ gameCount === 1 ? '' : 's' }}
              <template v-if="typeof finishedGameCount === 'number' && gameCount > 0">
                · {{ finishedGameCount }} finalizado{{ finishedGameCount === 1 ? '' : 's' }}
              </template>
            </p>
          </div>
        </div>

        <div class="space-y-3 border-t border-slate-200 px-4 pb-4 pt-3 dark:border-slate-700">
          <p
            v-if="randomGroupsSuccessMessage"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
          >
            {{ randomGroupsSuccessMessage }}
          </p>

          <div v-if="groupStructureAction" class="space-y-2">
            <button
              v-if="groupStructureAction.type === 'modal'"
              type="button"
              class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
              @click="openGenerateRandomGroupsModal"
            >
              <Squares2X2Icon class="h-4 w-4" />
              {{ groupStructureAction.label }}
            </button>

            <p
              v-else
              class="rounded-md border border-dashed border-slate-300 px-3 py-2 text-sm text-slate-600 dark:border-slate-600 dark:text-slate-400"
            >
              {{ groupStructureAction.description }}
            </p>

            <p
              v-if="groupStructureAction.type === 'modal'"
              class="text-xs text-slate-500 dark:text-slate-400"
            >
              {{ groupStructureAction.description }}
            </p>
          </div>

          <div v-if="canRegenerateRandomGroups">
            <button
              type="button"
              class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100 dark:hover:bg-amber-950/50"
              @click="openRegenerateRandomGroupsModal"
            >
              Regenerar grupos y partidos
            </button>
          </div>

          <p
            v-if="!hasExistingGroups && groups !== null"
            class="text-sm text-slate-600 dark:text-slate-300"
          >
            Todavía no hay grupos generados para esta competencia.
          </p>

          <template v-if="hasExistingGroups">
            <p
              class="rounded-md px-3 py-2 text-xs font-medium"
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

            <div class="space-y-3">
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
          </template>
        </div>
      </div>

      <div :class="sectionCardClasses">
        <div class="flex flex-wrap items-start gap-3 p-4">
          <span :class="groupPhaseAccordionIconContainerClasses">
            <TrophyIcon :class="groupPhaseAccordionIconClasses" />
          </span>

          <div class="min-w-0 flex-1 space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="font-medium text-slate-900 dark:text-slate-100">Llave eliminatoria</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                  <template v-if="hasBracket">
                    {{ bracketStatus || 'Generada' }}
                    <template v-if="bracketGameCount > 0">
                      · {{ bracketGameCount }} partido{{ bracketGameCount === 1 ? '' : 's' }}
                    </template>
                  </template>
                  <template v-else>Todavía no generada</template>
                </p>
              </div>

              <span
                v-if="!hasBracket && bracketCompactStats?.badge"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="
                  bracketCompactStats.hasQualifyingRound
                    ? 'bg-violet-100 text-violet-800 dark:bg-violet-900/60 dark:text-violet-200'
                    : 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200'
                "
              >
                {{ bracketCompactStats.badge }}
              </span>
            </div>

            <template v-if="hasBracket">
              <RouterLink
                :to="bracketRoute"
                class="inline-flex rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
              >
                Ver llave eliminatoria
              </RouterLink>
            </template>

            <template v-else>
              <p
                v-if="isKnockoutDirect"
                class="text-sm text-slate-600 dark:text-slate-400"
              >
                Esta competencia es de eliminación directa. La llave se generará con los
                {{ registeredCount }} jugador{{ registeredCount === 1 ? '' : 'es' }}
                inscripto{{ registeredCount === 1 ? '' : 's' }}.
              </p>

              <p
                v-if="bracketBlockMessage"
                class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
              >
                {{ bracketBlockMessage }}
              </p>

              <dl
                v-if="bracketCompactStats"
                class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4"
              >
                <div>
                  <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Clasificados por grupo
                  </dt>
                  <dd class="mt-0.5 font-medium text-slate-900 dark:text-slate-100">
                    {{ bracketCompactStats.qualifiedPerGroup }}
                  </dd>
                </div>

                <div v-if="bracketCompactStats.totalQualified !== null">
                  <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Total clasificados
                  </dt>
                  <dd class="mt-0.5 font-medium text-slate-900 dark:text-slate-100">
                    {{ bracketCompactStats.totalQualified }}
                  </dd>
                </div>

                <div v-if="bracketCompactStats.bracketSize !== null">
                  <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Tamaño de la llave
                  </dt>
                  <dd class="mt-0.5 font-medium text-slate-900 dark:text-slate-100">
                    {{ bracketCompactStats.bracketSize }}
                  </dd>
                </div>

                <div v-if="bracketCompactStats.byesCount !== null && bracketCompactStats.byesCount > 0">
                  <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Pases directos
                  </dt>
                  <dd class="mt-0.5 font-medium text-slate-900 dark:text-slate-100">
                    {{ bracketCompactStats.byesCount }}
                  </dd>
                </div>
              </dl>

              <div
                v-if="bracketCompactStats?.warnings?.length"
                class="space-y-2"
              >
                <p
                  v-for="(warning, index) in bracketCompactStats.warnings"
                  :key="`bracket-warning-${index}`"
                  class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
                >
                  {{ warning }}
                </p>
              </div>

              <details
                v-if="hasBracketGenerationDetails"
                class="group/bracket-details rounded-md border border-slate-200 dark:border-slate-700"
              >
                <summary
                  class="cursor-pointer px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50 dark:text-slate-400 dark:hover:bg-slate-800/50 [&::-webkit-details-marker]:hidden"
                >
                  Ver detalles de generación
                </summary>

                <div class="space-y-2 border-t border-slate-200 px-3 py-3 dark:border-slate-700">
                  <p
                    v-for="(line, index) in bracketGenerationPreview.introLines"
                    :key="`intro-${index}`"
                    class="text-slate-600 dark:text-slate-300"
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
                </div>
              </details>

              <div v-if="bracketStructureAction?.type === 'link'" class="pt-1">
                <RouterLink
                  :to="bracketStructureAction.to"
                  class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
                >
                  <TrophyIcon class="h-4 w-4" />
                  {{ bracketStructureAction.label }}
                </RouterLink>
              </div>
            </template>
          </div>
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
        class="group/config overflow-hidden rounded-md border border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <summary :class="groupPhaseAccordionSummaryClasses">
          <span :class="groupPhaseAccordionIconContainerClasses">
            <Cog6ToothIcon :class="groupPhaseAccordionIconClasses" />
          </span>

          <div class="min-w-0 flex-1">
            <p class="font-medium text-slate-900 dark:text-slate-100">
              Reglas y configuración
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
              Formato, categoría y reglas de partidos
            </p>
          </div>

          <ChevronDownIcon
            class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200 group-open/config:rotate-180"
            aria-hidden="true"
          />
        </summary>

        <div class="border-t border-slate-200 px-4 pb-4 pt-3 dark:border-slate-700">
          <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Nombre</dt>
              <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">{{ competition.name }}</dd>
            </div>

            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Categoría</dt>
              <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">{{ competition.category }}</dd>
            </div>

            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Tipo</dt>
              <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                {{ getCompetitionTypeLabel(competition.type) }}
              </dd>
            </div>

            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Formato</dt>
              <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">{{ formatLabel }}</dd>
            </div>

            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Puntos por set</dt>
              <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">{{ competition.points_per_set }}</dd>
            </div>
          </dl>

          <div class="mt-3 border-t border-slate-200 pt-3 dark:border-slate-700">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
              Formato de partidos
            </p>
            <ul class="mt-2 space-y-1 text-sm text-slate-600 dark:text-slate-400">
              <li v-if="hasGroupStage">
                Grupos: mejor de {{ formatCount(competition.group_stage_best_of) }}
              </li>
              <li>Eliminatorias: mejor de {{ formatCount(competition.knockout_stage_best_of) }}</li>
              <li>Semifinal: mejor de {{ formatCount(competition.semifinal_best_of) }}</li>
              <li>Final: mejor de {{ formatCount(competition.final_best_of) }}</li>
            </ul>
          </div>
        </div>
      </details>

      <CompetitionParticipantsModal
        v-if="registrations !== null"
        :show="showParticipantsModal"
        :registrations="registrations"
        :registrations-editable="registrationsEditable"
        :registrations-route="registrationsRoute"
        @close="showParticipantsModal = false"
      />

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

      <RegenerateRandomGroupsModal
        v-if="hasGroupStage"
        :show="showRegenerateRandomGroupsModal"
        :competition-id="competitionId"
        :registered-count="registeredCount"
        :existing-groups-count="existingGroupsCount"
        :is-competition-completed="isCompetitionCompleted"
        @close="showRegenerateRandomGroupsModal = false"
        @saved="handleRegenerateRandomGroupsSaved"
      />

      <CompetitionFormModal
        :show="showEditCompetitionModal"
        mode="edit"
        :competition="competition"
        :competition-id="competitionId"
        @close="handleEditCompetitionClose"
        @saved="handleEditCompetitionSaved"
      />
    </template>
  </section>
</template>
