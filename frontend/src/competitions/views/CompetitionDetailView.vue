<script setup>
import {
  ChevronDownIcon,
  Cog6ToothIcon,
  PlusIcon,
  PrinterIcon,
  Squares2X2Icon,
  TrophyIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import { usePermissions } from '../../composables/usePermissions'
import BracketService from '../../brackets/services/BracketService'
import { getBracketMatches } from '../../brackets/utils/bracketMatchAdapter'
import GameService from '../../games/services/GameService'
import GroupService from '../../groups/services/GroupService'
import RegistrationService from '../../registrations/services/RegistrationService'
import GenerateRandomGroupsModal from '../../groups/components/GenerateRandomGroupsModal.vue'
import CreateGroupModal from '../../groups/components/CreateGroupModal.vue'
import RegenerateRandomGroupsModal from '../../groups/components/RegenerateRandomGroupsModal.vue'
import { buildRandomGroupsSuccessMessage } from '../../groups/utils/buildRandomGroupsSuccessMessage'
import { buildRegenerateRandomGroupsSuccessMessage } from '../../groups/utils/buildRegenerateRandomGroupsSuccessMessage'
import {
  canMutateGroupComposition,
  groupCompositionLockMessage,
} from '../../groups/utils/canMutateGroupComposition'
import StandingService from '../../standings/services/StandingService'
import { buildBracketGenerationPreview } from '../utils/buildBracketGenerationPreview'
import { buildGroupPhaseAlert, summarizeGroupPhaseBracketGate } from '../utils/buildGroupPhaseAlert'
import {
  competitionHasGroupStage,
  getCompetitionFormatLabel,
} from '../constants/competitionFormats'
import {
  getCompetitionTypeLabel,
  getParticipantKind,
  isTeamCompetition,
  participantPlural,
} from '../../shared/constants/competitionType'
import CompetitionContextHint from '../components/CompetitionContextHint.vue'
import CompetitionCheckInSection from '../components/CompetitionCheckInSection.vue'
import CompetitionFormModal from '../components/CompetitionFormModal.vue'
import CompetitionParticipantsModal from '../components/CompetitionParticipantsModal.vue'
import CompetitionPodiumSummary from '../components/CompetitionPodiumSummary.vue'
import CompetitionService from '../services/CompetitionService'
import {
  isRegistrationsEditable,
  isStructureEditable,
  registrationsLockReason,
  structureLockReason,
} from '../utils/competitionStructure'
import {
  getStatusBadgeClasses,
  getStatusLabel,
} from '../utils/competitionListDisplay'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const canManageCompetitions = computed(() => can('competitions.manage'))
const canManageGroups = computed(() => can('groups.manage'))
const canRegenerateGroups = computed(() => can('groups.regenerate'))
const canManageRegistrations = computed(() => can('registrations.manage'))

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
const showCreateGroupModal = ref(false)
const showEditCompetitionModal = ref(false)
const showParticipantsModal = ref(false)
const checkInMeta = ref({
  pendingMembers: 0,
  checkedInMembers: 0,
  tournamentFinished: false,
})
const openSections = ref({
  checkIn: false,
  groups: false,
  bracket: false,
})
const didApplyDefaultSection = ref(false)

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

const registeredCount = computed(() => registrations.value?.length ?? 0)

const participantKind = computed(() => getParticipantKind(competition.value))

const participantsCountLabel = computed(() => {
  const count = registeredCount.value

  return `${count} inscripto${count === 1 ? '' : 's'}`
})

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
    canManageGroups.value &&
    competitionStructureEditable.value &&
    hasGroupStage.value &&
    registeredCount.value >= 2 &&
    !hasExistingGroups.value &&
    !isCompetitionCompleted.value &&
    groups.value !== null,
)

const canRegenerateRandomGroups = computed(
  () =>
    canRegenerateGroups.value &&
    competitionStructureEditable.value &&
    hasGroupStage.value &&
    hasExistingGroups.value &&
    registeredCount.value >= 2 &&
    !isCompetitionCompleted.value &&
    groups.value !== null,
)

const existingGroupsCount = computed(() => groups.value?.length ?? 0)

const groupCount = computed(() => (groups.value === null ? '-' : groups.value.length))

const isTeam = computed(() => isTeamCompetition(competition.value))

const gameCount = computed(() => {
  if (isTeam.value) {
    if (competition.value?.team_ties_count == null) {
      return '-'
    }

    return competition.value.team_ties_count
  }

  return games.value === null ? '-' : games.value.length
})

const finishedGameCount = computed(() => {
  if (isTeam.value) {
    if (competition.value?.finished_team_ties_count == null) {
      return '-'
    }

    return competition.value.finished_team_ties_count
  }

  if (games.value === null) {
    return '-'
  }

  return games.value.filter((game) => game.status === 'finished').length
})

const categoryModalityLabel = computed(() =>
  [competition.value?.category, getCompetitionTypeLabel(competition.value?.type)]
    .filter(Boolean)
    .join(' · '),
)

const compactMetricsLabel = computed(() => {
  const parts = []
  const count = registeredCount.value
  const people = participantPlural(competition.value)

  parts.push(`${count} ${people}`)

  if (hasGroupStage.value && groups.value !== null) {
    const groupTotal = groups.value.length
    parts.push(`${groupTotal} grupo${groupTotal === 1 ? '' : 's'}`)
  }

  if (typeof gameCount.value === 'number' && typeof finishedGameCount.value === 'number' && gameCount.value > 0) {
    const unit = isTeam.value ? 'enfrentamiento' : 'partido'
    const unitLabel = `${unit}${gameCount.value === 1 ? '' : 's'}`

    parts.push(
      `${finishedGameCount.value} de ${gameCount.value} ${unitLabel} finalizado${gameCount.value === 1 ? '' : 's'}`,
    )
  }

  return parts.join(' · ')
})

const bracketGames = computed(() => {
  if (!games.value) {
    return []
  }

  return games.value.filter((game) => game.bracket_id)
})

const hasBracket = computed(() => Boolean(bracket.value?.id))

const canCreateGroup = computed(
  () =>
    canManageGroups.value &&
    canMutateGroupComposition(competition.value, { hasBracket: hasBracket.value }),
)

const groupCompositionLockedMessage = computed(() =>
  groupCompositionLockMessage({ hasBracket: hasBracket.value }),
)

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

const bracketMatchList = computed(() =>
  getBracketMatches(bracket.value, isTeamCompetition(competition.value)),
)

const bracketGameCount = computed(
  () => bracketMatchList.value.length || bracketGames.value.length,
)

const bracketStatus = computed(() => {
  const bracketGameList = bracketMatchList.value.length
    ? bracketMatchList.value
    : bracketGames.value

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

const attentionItems = computed(() => {
  const items = []
  const statusCode = statusSummary.value?.code

  if (
    checkInMeta.value.pendingMembers > 0 &&
    !checkInMeta.value.tournamentFinished &&
    statusCode !== 'completed'
  ) {
    const pending = checkInMeta.value.pendingMembers
    items.push(
      `${pending} participante${pending === 1 ? '' : 's'} pendiente${pending === 1 ? '' : 's'} de check-in`,
    )
  }

  for (const summary of groupPhaseSummaries.value) {
    const groupName = summary.group?.name ?? 'Grupo'

    if (summary.hasPendingManualTiebreak) {
      items.push(`${groupName}: desempate manual`)
      continue
    }

    if (summary.hasStaleManualTiebreaks) {
      items.push(`${groupName}: desempate desactualizado`)
      continue
    }

    if (summary.pendingGamesCount > 0) {
      const count = summary.pendingGamesCount
      const unit = String(summary.primaryLabel ?? '').includes('Enfrentamiento')
        ? 'enfrentamiento'
        : 'partido'
      items.push(
        `${groupName}: ${count} ${unit}${count === 1 ? '' : 's'} pendiente${count === 1 ? '' : 's'}`,
      )
    }
  }

  return items
})

const pendingGroupGamesCount = computed(() =>
  groupPhaseSummaries.value.reduce((total, summary) => total + (summary.pendingGamesCount ?? 0), 0),
)

const compactGroupStatus = (summary) => {
  if (summary.pendingGamesCount > 0) {
    const count = summary.pendingGamesCount
    const unit = String(summary.primaryLabel ?? '').includes('Enfrentamiento')
      ? 'enfrentamiento'
      : 'partido'

    return `${count} ${unit}${count === 1 ? '' : 's'} pendiente${count === 1 ? '' : 's'}`
  }

  return summary.primaryLabel
}

const extraGroupAlerts = (summary) => {
  const statusLabel = compactGroupStatus(summary)

  return (summary.alerts ?? []).filter((alert) => alert.label !== statusLabel && alert.label !== summary.primaryLabel)
}

const resolveDefaultOpenSection = (code, registered) => {
  switch (code) {
    case 'awaiting_registrations':
      return 'participants'
    case 'no_groups':
      return registered > 0 ? 'check-in' : 'participants'
    case 'group_stage_pending':
    case 'group_stage_in_progress':
    case 'group_stage_attention_required':
      return 'groups'
    case 'ready_for_bracket':
    case 'knockout_in_progress':
    case 'completed':
      return 'bracket'
    default:
      return null
  }
}

const applyDefaultOpenSection = () => {
  if (didApplyDefaultSection.value || !statusSummary.value?.code) {
    return
  }

  const section = resolveDefaultOpenSection(
    statusSummary.value.code,
    registrations.value?.length ?? 0,
  )

  didApplyDefaultSection.value = true
  openSections.value = {
    checkIn: section === 'check-in',
    groups: section === 'groups',
    bracket: section === 'bracket',
  }
}

const toggleSection = (key) => {
  openSections.value = {
    ...openSections.value,
    [key]: !openSections.value[key],
  }
}

const handleCheckInSummaryChange = (meta) => {
  checkInMeta.value = {
    pendingMembers: meta?.pendingMembers ?? 0,
    checkedInMembers: meta?.checkedInMembers ?? 0,
    tournamentFinished: Boolean(meta?.tournamentFinished),
  }
}

const groupDetailRoute = (group) => ({
  path: `/groups/${group.id}`,
  query: {
    competitionId: competitionId.value,
    groupName: group.name,
  },
})

const canPrintAllGroups = computed(() => {
  if (isTeam.value || !hasExistingGroups.value || !Array.isArray(games.value)) {
    return false
  }

  return groups.value.every((group) =>
    games.value.some((game) => Number(game.group_id) === Number(group.id)),
  )
})

const printAllGroupsRoute = computed(() => ({
  name: 'competitions-groups-print',
  params: { id: String(competitionId.value) },
}))

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
      label: 'Ver llave',
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
        ? `Necesitás al menos 2 ${participantPlural(competition.value)} inscriptos`
        : `Distribuir ${participantPlural(competition.value)} inscriptos en grupos`,
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
      description: `Necesitás al menos 2 ${participantPlural(competition.value)} inscriptos`,
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

const groupsCollapsedSummary = computed(() => {
  if (groups.value === null) {
    return 'Cargando grupos...'
  }

  if (!hasExistingGroups.value) {
    return 'Todavía no hay grupos generados'
  }

  const total = groupCount.value
  const parts = [`${total} grupo${total === 1 ? '' : 's'}`]

  if (typeof gameCount.value === 'number' && typeof finishedGameCount.value === 'number' && gameCount.value > 0) {
    parts.push(`${finishedGameCount.value} de ${gameCount.value} finalizados`)
  }

  return parts.join(' · ')
})

const bracketCollapsedSummary = computed(() => {
  if (hasBracket.value) {
    if (isCompetitionCompleted.value) {
      return bracketStatus.value || 'Completa'
    }

    const status = bracketStatus.value || 'Generada'

    if (bracketGameCount.value > 0) {
      return `${status} · ${bracketGameCount.value} partido${bracketGameCount.value === 1 ? '' : 's'}`
    }

    return status
  }

  if (pendingGroupGamesCount.value > 0) {
    const count = pendingGroupGamesCount.value
    const unit = isTeam.value ? 'enfrentamiento' : 'partido'

    return `Faltan ${count} ${unit}${count === 1 ? '' : 's'} de grupos`
  }

  if (statusSummary.value?.code === 'ready_for_bracket') {
    return 'Generar llave'
  }

  if (bracketBlockMessage.value) {
    return bracketBlockMessage.value
  }

  return 'Todavía no generada'
})

const bracketHeaderAction = computed(() => {
  const action = bracketStructureAction.value

  if (action?.type === 'link') {
    return action
  }

  return null
})

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
    const competitionData = await CompetitionService.show(competitionId.value)

    const [registrationsData, groupsData, gamesData, bracketData] = await Promise.all([
      RegistrationService.listByCompetition(competitionId.value).catch(() => null),
      GroupService.listByCompetition(competitionId.value).catch(() => null),
      isTeamCompetition(competitionData)
        ? Promise.resolve(null)
        : GameService.listByCompetition(competitionId.value).catch(() => null),
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

    applyDefaultOpenSection()
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

const openCreateGroupModal = () => {
  showCreateGroupModal.value = true
}

const handleCreateGroupSaved = async (group) => {
  showCreateGroupModal.value = false

  if (!group?.id) {
    await loadCompetitionSummary()
    return
  }

  await router.push({
    path: `/groups/${group.id}`,
    query: {
      competitionId: String(competitionId.value),
      groupName: group.name ?? '',
    },
  })
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
          v-if="canManageCompetitions"
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
      <div
        class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900/60"
      >
        <div class="flex flex-wrap items-center gap-2">
          <p v-if="categoryModalityLabel" class="text-sm text-slate-600 dark:text-slate-300">
            {{ categoryModalityLabel }}
          </p>
          <span
            v-if="statusSummary"
            class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
            :class="getStatusBadgeClasses(competition)"
          >
            {{ getStatusLabel(competition) }}
          </span>
        </div>

        <p class="mt-2 text-sm font-medium text-slate-800 dark:text-slate-100">
          {{ compactMetricsLabel }}
        </p>

        <p
          v-if="!isTeam && games !== null && games.length === 0"
          class="mt-2 text-xs text-slate-500 dark:text-slate-400"
        >
          No hay partidos generados
        </p>

        <p
          v-else-if="isTeam && competition.team_ties_count === 0"
          class="mt-2 text-xs text-slate-500 dark:text-slate-400"
        >
          No hay enfrentamientos generados
        </p>

        <p v-if="!isTeam && games !== null && games.length > 0" class="mt-2">
          <RouterLink
            :to="`/competitions/${competitionId}/games`"
            class="text-xs text-slate-500 underline-offset-2 hover:text-slate-700 hover:underline dark:text-slate-400 dark:hover:text-slate-300"
          >
            Ver todos los partidos
          </RouterLink>
        </p>
      </div>

      <div
        v-if="attentionItems.length > 0"
        class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm dark:border-amber-900 dark:bg-amber-950/30"
      >
        <p class="font-medium text-amber-900 dark:text-amber-100">Requiere atención</p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-amber-900 dark:text-amber-100">
          <li v-for="(item, index) in attentionItems" :key="`attention-${index}`">
            {{ item }}
          </li>
        </ul>
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
            <span class="mt-0.5 block text-xs font-medium text-slate-700 dark:text-slate-300">
              {{ participantsCountLabel }}
            </span>
          </span>

          <ChevronDownIcon
            class="h-5 w-5 shrink-0 -rotate-90 text-slate-400"
            aria-hidden="true"
          />
        </span>
      </button>

      <CompetitionCheckInSection
        :competition-id="competitionId"
        :can-manage="canManageRegistrations"
        :expanded="openSections.checkIn"
        @toggle="toggleSection('checkIn')"
        @summary-change="handleCheckInSummaryChange"
      />

      <div
        v-if="hasGroupStage"
        :class="sectionCardClasses"
      >
        <div class="flex items-start gap-2 p-2 pr-3 sm:p-0">
          <button
            type="button"
            :class="[groupPhaseAccordionSummaryClasses, 'min-w-0 flex-1']"
            :aria-expanded="openSections.groups"
            @click="toggleSection('groups')"
          >
            <span :class="groupPhaseAccordionIconContainerClasses">
              <Squares2X2Icon :class="groupPhaseAccordionIconClasses" />
            </span>

            <span class="min-w-0 flex-1 text-left">
              <span class="block font-medium text-slate-900 dark:text-slate-100">Fase de grupos</span>
              <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                {{ groupsCollapsedSummary }}
              </span>
            </span>

            <ChevronDownIcon
              class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200"
              :class="openSections.groups ? 'rotate-180' : ''"
              aria-hidden="true"
            />
          </button>

          <button
            v-if="canCreateGroup"
            type="button"
            class="mt-3 inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
            @click="openCreateGroupModal"
          >
            <PlusIcon class="h-4 w-4" aria-hidden="true" />
            Crear grupo
          </button>
        </div>

        <div
          v-show="openSections.groups"
          class="space-y-3 border-t border-slate-200 px-4 pb-4 pt-3 dark:border-slate-700"
        >
          <p
            v-if="randomGroupsSuccessMessage"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
          >
            {{ randomGroupsSuccessMessage }}
          </p>

          <p
            v-if="groupCompositionLockedMessage"
            class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300"
          >
            {{ groupCompositionLockedMessage }}
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
            <RouterLink
              v-if="canPrintAllGroups"
              :to="printAllGroupsRoute"
              class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
            >
              <PrinterIcon class="h-4 w-4" />
              Imprimir todos los grupos
            </RouterLink>

            <div class="space-y-2">
              <article
                v-for="summary in groupPhaseSummaries"
                :key="summary.group.id"
                class="flex flex-wrap items-center gap-2 rounded-md border border-slate-200 px-3 py-2 dark:border-slate-700"
              >
                <div class="min-w-0 flex-1">
                  <p class="font-medium text-slate-900 dark:text-slate-100">{{ summary.group.name }}</p>
                  <div v-if="extraGroupAlerts(summary).length > 0" class="mt-1 flex flex-wrap gap-1">
                    <span
                      v-for="(alert, alertIndex) in extraGroupAlerts(summary)"
                      :key="`${summary.group.id}-alert-${alertIndex}`"
                      class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                      :class="groupPhaseAlertChipClasses(alert.type)"
                    >
                      {{ alert.label }}
                    </span>
                  </div>
                </div>

                <span
                  class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="groupPhasePrimaryBadgeClasses(summary.primaryType)"
                >
                  {{ compactGroupStatus(summary) }}
                </span>

                <RouterLink
                  :to="groupDetailRoute(summary.group)"
                  class="inline-flex rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
                >
                  Abrir
                </RouterLink>
              </article>
            </div>
          </template>
        </div>
      </div>

      <div :class="sectionCardClasses">
        <div class="flex items-start gap-2 p-2 pr-3 sm:p-0">
          <button
            type="button"
            :class="[groupPhaseAccordionSummaryClasses, 'min-w-0 flex-1']"
            :aria-expanded="openSections.bracket"
            @click="toggleSection('bracket')"
          >
            <span :class="groupPhaseAccordionIconContainerClasses">
              <TrophyIcon :class="groupPhaseAccordionIconClasses" />
            </span>

            <span class="min-w-0 flex-1 text-left">
              <span class="block font-medium text-slate-900 dark:text-slate-100">Llave eliminatoria</span>
              <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                {{ bracketCollapsedSummary }}
              </span>
            </span>

            <ChevronDownIcon
              class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200"
              :class="openSections.bracket ? 'rotate-180' : ''"
              aria-hidden="true"
            />
          </button>

          <RouterLink
            v-if="bracketHeaderAction"
            :to="bracketHeaderAction.to"
            class="mt-3 inline-flex shrink-0 rounded-md bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          >
            {{ bracketHeaderAction.label }}
          </RouterLink>
        </div>

        <div
          v-show="openSections.bracket"
          class="space-y-3 border-t border-slate-200 px-4 pb-4 pt-3 dark:border-slate-700"
        >
          <template v-if="resultSummary">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
              Resultado final
            </p>
            <CompetitionPodiumSummary :result-summary="resultSummary" />
          </template>

          <p
            v-if="isKnockoutDirect && !hasBracket"
            class="text-sm text-slate-600 dark:text-slate-400"
          >
            Esta competencia es de eliminación directa. La llave se generará con los
            {{ registeredCount }} {{ participantPlural(competition) }} inscriptos.
          </p>

          <dl
            v-if="!hasBracket && bracketCompactStats"
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
            v-if="!hasBracket && bracketCompactStats?.warnings?.length"
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
            v-if="!hasBracket && hasBracketGenerationDetails"
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
          <CompetitionContextHint
            v-if="!competitionStructureEditable && competitionStructureLockReason"
            :message="competitionStructureLockReason"
            variant="warning"
            use-lock-icon
            class="mb-3"
          />

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
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Modalidad tercer puesto</dt>
              <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                {{ competition.third_place_mode_label ?? '—' }}
              </dd>
            </div>

            <div v-if="(resultSummary?.third_place_game_id || resultSummary?.third_place_team_tie_id) && statusSummary?.code !== 'completed'">
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Tercer puesto</dt>
              <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                <RouterLink
                  :to="bracketRoute"
                  class="text-slate-700 underline hover:text-slate-900 dark:text-slate-300 dark:hover:text-slate-100"
                >
                  {{ statusSummary?.next_action ?? (isTeam ? 'Ver enfrentamiento por tercer puesto' : 'Ver partido por tercer puesto') }}
                </RouterLink>
              </dd>
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
        :competition="competition"
        :registrations="registrations"
        :registrations-editable="registrationsEditable && canManageRegistrations"
        :registrations-lock-message="registrationsEditable ? null : registrationsLockMessage"
        :registrations-route="registrationsRoute"
        @close="showParticipantsModal = false"
      />

      <CreateGroupModal
        v-if="hasGroupStage"
        :show="showCreateGroupModal"
        :competition-id="competitionId"
        :existing-groups="groups ?? []"
        @close="showCreateGroupModal = false"
        @saved="handleCreateGroupSaved"
      />

      <GenerateRandomGroupsModal
        v-if="hasGroupStage"
        :show="showGenerateRandomGroupsModal"
        :competition-id="competitionId"
        :registered-count="registeredCount"
        :has-existing-groups="hasExistingGroups"
        :is-competition-completed="isCompetitionCompleted"
        :participant-kind="participantKind"
        :entries="registrations ?? []"
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
        :participant-kind="participantKind"
        :entries="registrations ?? []"
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
