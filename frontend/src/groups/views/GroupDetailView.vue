<script setup>
import { ChartBarIcon, ChevronDownIcon, PlusIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import { usePermissions } from '../../composables/usePermissions'
import { useTransientMessage } from '../../composables/useTransientMessage'
import BracketService from '../../brackets/services/BracketService'
import CompetitionService from '../../competitions/services/CompetitionService'
import {
  getParticipantKind,
  isMultiMemberCompetition,
  isTeamCompetition,
  participantPlural,
  participantSingular,
} from '../../shared/constants/competitionType'
import GameResultModal from '../../games/components/GameResultModal.vue'
import GameService from '../../games/services/GameService'
import {
  gameMatchupLabel,
  getGameSideDisplayName,
  getGameWinnerDisplayName,
  isGameBye,
} from '../../games/utils/gameDisplay'
import {
  getGameStatusBadgeClasses,
  getGameStatusLabel,
} from '../../shared/constants/gameStatus'
import StandingService from '../../standings/services/StandingService'
import GroupStandingsTable from '../../standings/components/GroupStandingsTable.vue'
import { buildGroupStandingRows } from '../../standings/utils/buildGroupStandingRows'
import GroupPlayerStatusModal from '../components/GroupPlayerStatusModal.vue'
import AssignGroupEntryModal from '../components/AssignGroupEntryModal.vue'
import GroupEntryActionsMenu from '../components/GroupEntryActionsMenu.vue'
import MoveGroupEntryModal from '../components/MoveGroupEntryModal.vue'
import RemoveGroupEntryModal from '../components/RemoveGroupEntryModal.vue'
import { getGroupPlayerStatusLabel } from '../constants/groupPlayerStatus'
import GroupService from '../services/GroupService'
import {
  canMutateGroupComposition,
  groupCompositionLockMessage,
} from '../utils/canMutateGroupComposition'
import {
  canEditGroupEntry,
  groupEntryLockMessage,
} from '../utils/canEditGroupEntry'
import { listMoveTargetGroups } from '../utils/listMoveTargetGroups'
import {
  collectAssignedEntryIds,
  listUnassignedEntries,
} from '../utils/unassignedCompetitionEntries'
import RegistrationService from '../../registrations/services/RegistrationService'
import { buildGroupOperationalStatus } from '../utils/buildGroupOperationalStatus'
import {
  buildGroupRoundSections,
  mergeRoundOpenState,
  roundSectionKey,
} from '../utils/buildGroupRoundSections'
import {
  areAllCompetitionGroupsComplete,
  buildGroupResultNavigation,
  findFirstPendingGameInGroup,
  findNavigableGame,
  findNextGroupWithPendingGames,
  findNextPendingGame,
  isGroupScheduleComplete,
  selectGameWhenChangingGroup,
  sortCompetitionGroupsByName,
} from '../utils/groupResultNavigation'
import TeamTieService from '../../team-ties/services/TeamTieService'
import {
  getTeamTieStatusBadgeClasses,
  getTeamTieStatusLabel,
  isTeamTiePending,
} from '../../team-ties/constants/teamTieStatus'

const route = useRoute()
const { can } = usePermissions()
const canManageGroups = computed(() => can('groups.manage'))
const canRecordResults = computed(() => can('matches.record_result'))

const groupId = computed(() => route.params.id)
const competitionId = computed(() => route.query.competitionId || '')
const groupName = computed(() => route.query.groupName || `Grupo #${groupId.value}`)
const competition = ref(null)
const hasBracket = ref(false)

const qualifiedPerGroup = computed(() => competition.value?.qualified_per_group ?? 2)

const participantKind = computed(() => getParticipantKind(competition.value))
const isMultiMember = computed(() => isMultiMemberCompetition(competition.value))
const isTeam = computed(() => isTeamCompetition(competition.value))
const hasTeamTieFormat = computed(() => Boolean(competition.value?.team_tie_format_id))
const canGenerateRoundRobin = computed(() => !isTeam.value || hasTeamTieFormat.value)

const canEditGroupComposition = computed(
  () =>
    canManageGroups.value &&
    canMutateGroupComposition(competition.value, { hasBracket: hasBracket.value }),
)

const canAddGroupEntry = canEditGroupComposition

const compositionLockMessage = computed(() =>
  groupCompositionLockMessage({ hasBracket: hasBracket.value }),
)

const addGroupEntryLabel = computed(() => {
  if (participantKind.value === 'pair') {
    return 'Agregar pareja'
  }

  return 'Agregar participante'
})

const moveGroupEntryLabel = computed(() => {
  if (participantKind.value === 'pair') {
    return 'Mover pareja'
  }

  return 'Mover a otro grupo'
})

const removeGroupEntryLabel = computed(() => {
  if (participantKind.value === 'pair') {
    return 'Quitar pareja'
  }

  return 'Quitar del grupo'
})

const entryActionsLockMessage = computed(() =>
  groupEntryLockMessage({ participantKind: participantKind.value }),
)

const groupPlayers = ref([])
const isLoadingGroupPlayers = ref(false)
const groupPlayersError = ref('')
const registrations = ref([])
const showAssignEntryModal = ref(false)
const assignEntryNotice = useTransientMessage()
const openEntryActionsId = ref(null)
const selectedEntryForRemove = ref(null)
const selectedEntryForMove = ref(null)
const showRemoveEntryModal = ref(false)
const showMoveEntryModal = ref(false)

const standings = ref([])
const standingsMeta = ref({})
const isLoadingStandings = ref(false)

const isGeneratingRoundRobin = ref(false)
const roundRobinError = ref('')
const roundRobinNotice = useTransientMessage()

const selectedGroupEntryForStatus = ref(null)
const playerStatusNotice = useTransientMessage()

const standingRows = computed(() =>
  buildGroupStandingRows({
    standings: standings.value,
    meta: standingsMeta.value,
    qualifiedPerGroup: qualifiedPerGroup.value,
  }),
)

const groupPlayerByEntryId = computed(() => {
  const playersByEntryId = new Map()

  for (const groupPlayer of groupPlayers.value) {
    const entryId = Number(groupPlayer?.competition_entry_id)

    if (Number.isFinite(entryId)) {
      playersByEntryId.set(entryId, groupPlayer)
    }
  }

  return playersByEntryId
})

const groupPlayerForRow = (row) =>
  groupPlayerByEntryId.value.get(Number(row?.competitionEntryId)) ?? null

const managementGroupPlayers = computed(() => {
  if (standingRows.value.length === 0) {
    return groupPlayers.value
  }

  const listedIds = new Set(
    standingRows.value
      .map((row) => Number(row.competitionEntryId))
      .filter((entryId) => Number.isFinite(entryId)),
  )

  return groupPlayers.value.filter(
    (groupPlayer) => !listedIds.has(Number(groupPlayer?.competition_entry_id)),
  )
})

const isPlayerActive = (groupPlayer) => (groupPlayer?.status ?? 'active') === 'active'

const canChangePlayerStatus = (groupPlayer) =>
  isPlayerActive(groupPlayer) && !hasBracket.value && !hasGroupSchedule.value

const loadGroupPlayers = async () => {
  isLoadingGroupPlayers.value = true
  groupPlayersError.value = ''

  try {
    groupPlayers.value = await GroupService.listPlayers(groupId.value)
  } catch (error) {
    groupPlayersError.value =
      error?.response?.data?.message || `No se pudo cargar los ${participantPlural(competition.value)} del grupo.`
  } finally {
    isLoadingGroupPlayers.value = false
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

const groupPlayerNickname = (groupPlayer) => {
  if (isMultiMember.value || !groupPlayer?.player?.nickname) {
    return ''
  }

  return groupPlayer.player.nickname
}

const groupPlayersCount = computed(() => groupPlayers.value.length)

const emptyGroupMembersMessage = computed(
  () => `Todavía no hay ${participantPlural(competition.value)} en este grupo.`,
)

const availableEntries = computed(() =>
  listUnassignedEntries(
    registrations.value,
    collectAssignedEntryIds(competitionGroups.value, groupPlayers.value),
  ),
)

const moveTargetGroups = computed(() =>
  listMoveTargetGroups(competitionGroups.value, {
    currentGroupId: groupId.value,
    competitionId: competitionId.value || competition.value?.id,
  }),
)

const hasMoveTargets = computed(() => moveTargetGroups.value.length > 0)

const isGroupEntryEditable = (groupPlayer) => {
  if (isLoadingGames.value) {
    return false
  }

  return canEditGroupEntry(groupPlayer?.competition_entry_id, games.value, {
    groupId: groupId.value,
  })
}

const entryActionsDisabledMessage = (groupPlayer) => {
  if (isLoadingGames.value) {
    return 'Cargando partidos del grupo...'
  }

  if (!isGroupEntryEditable(groupPlayer)) {
    return entryActionsLockMessage.value
  }

  return ''
}

const closeEntryActionsMenu = () => {
  openEntryActionsId.value = null
}

const toggleEntryActionsMenu = (groupPlayer) => {
  const entryId = Number(groupPlayer?.competition_entry_id)

  if (!entryId) {
    return
  }

  openEntryActionsId.value = openEntryActionsId.value === entryId ? null : entryId
}

const entryActionsMenuLabel = (groupPlayer) =>
  `Acciones de ${groupEntryDisplayName(groupPlayer)}`

const groupPlayersCountLabel = computed(() => {
  const count = groupPlayersCount.value
  const label = participantPlural(competition.value)

  return `${count} ${label}`
})

const groupEntryDisplayName = (groupPlayer) =>
  groupPlayer?.display_name ||
  (groupPlayer?.player?.id
    ? `${groupPlayer.player.first_name} ${groupPlayer.player.last_name}`.trim()
    : 'Participación no asignada')

const playerStatusBadgeClasses = (status) => {
  if (status === 'withdrawn') {
    return 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'
  }

  if (status === 'disqualified') {
    return 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
  }

  return ''
}

const playerStatusLabel = (groupPlayer) => getGroupPlayerStatusLabel(groupPlayer?.status ?? 'active')

const loadStandings = async ({ silent = false } = {}) => {
  if (!silent) {
    isLoadingStandings.value = true
  }

  try {
    const { standings: groupStandings, meta } = await StandingService.listByGroup(groupId.value)
    standings.value = groupStandings
    standingsMeta.value = meta
  } catch {
    if (!silent) {
      standings.value = []
      standingsMeta.value = {}
    }
  } finally {
    if (!silent) {
      isLoadingStandings.value = false
    }
  }
}

const allCompetitionGames = ref([])
const competitionGroups = ref([])
const teamTies = ref([])
const isLoadingGames = ref(false)
const isLoadingTeamTies = ref(false)
const gamesError = ref('')
const teamTiesError = ref('')
const selectedGame = ref(null)
const activeModalGroupId = ref(null)
const resultNotice = useTransientMessage()
const modalStatusNotice = useTransientMessage()
const resultError = ref('')
const isRefreshingAfterSave = ref(false)

const games = computed(() =>
  allCompetitionGames.value.filter(
    (game) => Number(game.group_id) === Number(groupId.value),
  ),
)

const hasGroupGames = computed(() => games.value.length > 0)
const hasGroupTeamTies = computed(() => teamTies.value.length > 0)
const hasGroupSchedule = computed(() =>
  isTeam.value ? hasGroupTeamTies.value : hasGroupGames.value,
)

const canShowGenerateRoundRobin = computed(() => {
  if (!canManageGroups.value || hasGroupSchedule.value || !canGenerateRoundRobin.value) {
    return false
  }

  if (isTeam.value) {
    return true
  }

  return groupPlayers.value.length >= 2
})

const printGroupHref = computed(() => {
  const params = new URLSearchParams()

  if (competitionId.value) {
    params.set('competitionId', String(competitionId.value))
  }

  if (groupName.value) {
    params.set('groupName', String(groupName.value))
  }

  const query = params.toString()

  return `/groups/${groupId.value}/print${query ? `?${query}` : ''}`
})

const scheduleSectionTitle = computed(() => (isTeam.value ? 'Enfrentamientos' : 'Partidos'))

const isLoadingOperationalSummary = computed(
  () =>
    isLoadingGroupPlayers.value ||
    isLoadingStandings.value ||
    (isTeam.value ? isLoadingTeamTies.value : isLoadingGames.value),
)

const groupPlayersTitle = computed(() => {
  if (standings.value.length > 0) {
    return 'Posiciones'
  }

  if (hasGroupSchedule.value) {
    const label = participantPlural(competition.value)
    return `${label.charAt(0).toUpperCase()}${label.slice(1)} del grupo`
  }

  const label = participantPlural(competition.value)
  return `${label.charAt(0).toUpperCase()}${label.slice(1)} asignados`
})

const loadGroupTeamTies = async () => {
  if (!groupId.value) {
    teamTies.value = []
    return
  }

  isLoadingTeamTies.value = true
  teamTiesError.value = ''

  try {
    teamTies.value = await TeamTieService.listByGroup(groupId.value)
  } catch (error) {
    teamTies.value = []
    teamTiesError.value =
      error?.response?.data?.message || 'No se pudieron cargar los enfrentamientos del grupo.'
  } finally {
    isLoadingTeamTies.value = false
  }
}

const loadGroupSchedule = async (options) => {
  if (isTeam.value) {
    await loadGroupTeamTies()
    allCompetitionGames.value = []
    return
  }

  await loadGroupGames(options)
  teamTies.value = []
}

const loadCompetitionGroups = async () => {
  if (!competitionId.value) {
    competitionGroups.value = []
    return
  }

  try {
    competitionGroups.value = sortCompetitionGroupsByName(
      await GroupService.listByCompetition(competitionId.value),
    )
  } catch {
    competitionGroups.value = []
  }
}

const loadRegistrations = async () => {
  if (!competitionId.value) {
    registrations.value = []
    return
  }

  try {
    registrations.value = await RegistrationService.listByCompetition(competitionId.value)
  } catch {
    registrations.value = []
  }
}

const loadGroupGames = async ({ silent = false } = {}) => {
  if (!competitionId.value || !groupId.value) {
    if (!silent) {
      allCompetitionGames.value = []
    }
    return
  }

  if (!silent) {
    isLoadingGames.value = true
    gamesError.value = ''
  }

  try {
    allCompetitionGames.value = await GameService.listByCompetition(competitionId.value)
    gamesError.value = ''
  } catch (error) {
    if (!silent) {
      allCompetitionGames.value = []
      gamesError.value =
        error?.response?.data?.message || 'No se pudo cargar los partidos del grupo.'
    }

    if (silent) {
      throw error
    }
  } finally {
    if (!silent) {
      isLoadingGames.value = false
    }
  }
}

const teamTieMatchupLabel = (teamTie) => {
  const entry1 = teamTie?.entry1?.display_name ?? 'Equipo 1'
  const entry2 = teamTie?.entry2?.display_name ?? 'Equipo 2'

  return `${entry1} vs ${entry2}`
}

const teamTieScoreLabel = (teamTie) => {
  const score = teamTie?.score ?? { entry1: 0, entry2: 0 }
  const entry1 = teamTie?.entry1?.display_name ?? 'Equipo 1'
  const entry2 = teamTie?.entry2?.display_name ?? 'Equipo 2'

  return `${entry1} ${score.entry1} - ${score.entry2} ${entry2}`
}

const teamTieLineupSummary = (teamTie) => {
  const configured = teamTie?.rubbers_with_lineup ?? 0
  const total = teamTie?.rubbers_total ?? 0
  return `${configured}/${total} partidos con plantel definido`
}

const teamTieRoundSections = computed(() =>
  buildGroupRoundSections(teamTies.value, { kind: 'team_ties' }),
)

const isByeGame = isGameBye

const matchFormatLabel = (game) => {
  if (isByeGame(game)) {
    return null
  }

  if (game?.best_of && game?.sets_to_win) {
    return `Mejor de ${game.best_of} · gana con ${game.sets_to_win} sets`
  }

  if (game?.best_of) {
    return `Mejor de ${game.best_of}`
  }

  return null
}

const canLoadResult = (game) =>
  !isByeGame(game) && (game?.status === 'pending' || game?.status === 'in_progress')

const isFinishedGame = (game) => !isByeGame(game) && game?.status === 'finished'

const compareByGroupSchedule = (left, right) => {
  const leftHasRound = left.group_round != null
  const rightHasRound = right.group_round != null

  if (leftHasRound !== rightHasRound) {
    return leftHasRound ? -1 : 1
  }

  if (leftHasRound && rightHasRound) {
    if (left.group_round !== right.group_round) {
      return left.group_round - right.group_round
    }

    if (left.group_match !== right.group_match) {
      return left.group_match - right.group_match
    }
  }

  const statusOrder = { in_progress: 0, pending: 1 }
  const statusDiff = (statusOrder[left.status] ?? 2) - (statusOrder[right.status] ?? 2)

  if (statusDiff !== 0) {
    return statusDiff
  }

  return left.id - right.id
}

const pendingTeamTies = computed(() =>
  teamTies.value.filter((teamTie) => isTeamTiePending(teamTie.status)),
)

const scheduleCount = computed(() =>
  isTeam.value ? teamTies.value.length : games.value.length,
)

const pendingScheduleCount = computed(() =>
  isTeam.value ? pendingTeamTies.value.length : pendingLoadGames.value.length,
)

const finishedScheduleCount = computed(() =>
  isTeam.value
    ? teamTies.value.filter((teamTie) => teamTie.status === 'finished').length
    : finishedGames.value.length,
)

const scheduleHeadline = computed(() => {
  const unit = scheduleCount.value === 1
    ? (isTeam.value ? 'enfrentamiento' : 'partido')
    : (isTeam.value ? 'enfrentamientos' : 'partidos')
  const finishedLabel = finishedScheduleCount.value === 1
    ? '1 finalizado'
    : `${finishedScheduleCount.value} finalizados`

  return `${scheduleCount.value} ${unit} · ${finishedLabel}`
})

const groupHeadline = computed(() => {
  const parts = [
    `${groupPlayersCount.value} ${participantPlural(competition.value)}`,
    scheduleHeadline.value,
  ]

  if (!isTeam.value) {
    parts.push(`clasifican ${qualifiedPerGroup.value}`)
  }

  return parts.join(' · ')
})

const groupOperationalStatus = computed(() =>
  buildGroupOperationalStatus({
    scheduleCount: scheduleCount.value,
    pendingCount: pendingScheduleCount.value,
    requiresManualTiebreak:
      !standingsMeta.value?.standings_are_provisional &&
      Boolean(standingsMeta.value?.requires_manual_tiebreak),
    hasStaleManualTiebreaks:
      !standingsMeta.value?.standings_are_provisional &&
      (standingsMeta.value?.stale_manual_tiebreaks?.length ?? 0) > 0,
    isTeam: isTeam.value,
  }),
)

const groupOperationalStatusClasses = computed(() => {
  switch (groupOperationalStatus.value.tone) {
    case 'success':
      return 'text-emerald-800 dark:text-emerald-200'
    case 'warning':
      return 'text-amber-900 dark:text-amber-100'
    default:
      return 'text-slate-600 dark:text-slate-300'
  }
})

const groupToolbarButtonClasses =
  'inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800'

const pendingLoadGames = computed(() =>
  games.value.filter(canLoadResult).sort(compareByGroupSchedule),
)

const gameRoundSections = computed(() =>
  buildGroupRoundSections(games.value, { kind: 'games' }),
)

const finishedGames = computed(() =>
  games.value
    .filter(isFinishedGame)
    .sort((left, right) => {
      const leftFinishedAt = left.finished_at ?? ''
      const rightFinishedAt = right.finished_at ?? ''
      return rightFinishedAt.localeCompare(leftFinishedAt) || right.id - left.id
    }),
)

const byeGames = computed(() =>
  games.value.filter(isByeGame).sort((left, right) => left.id - right.id),
)

const activeRoundSections = computed(() =>
  isTeam.value ? teamTieRoundSections.value : gameRoundSections.value,
)

const roundOpenByKey = ref({})

watch(
  () => [String(groupId.value ?? ''), activeRoundSections.value],
  ([currentGroupId, sections], previous) => {
    if (!previous || previous[0] !== currentGroupId) {
      roundOpenByKey.value = {}
    }

    const merged = mergeRoundOpenState(roundOpenByKey.value, sections)

    if (merged !== roundOpenByKey.value) {
      roundOpenByKey.value = merged
    }
  },
  { immediate: true },
)

const isRoundSectionOpen = (section) => roundOpenByKey.value[roundSectionKey(section)] === true

const onRoundToggle = (section, event) => {
  const key = roundSectionKey(section)
  const isOpen = Boolean(event.currentTarget?.open)

  if (roundOpenByKey.value[key] === isOpen) {
    return
  }

  roundOpenByKey.value = {
    ...roundOpenByKey.value,
    [key]: isOpen,
  }
}

const roundStatusChipClasses = (section) =>
  section.isFinished
    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200'
    : 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'

const statusLabel = (game) => {
  if (isByeGame(game)) {
    return 'Avance automático'
  }

  return getGameStatusLabel(game?.status)
}

const statusBadgeClasses = (game) => {
  if (isByeGame(game) || game?.status === 'finished') {
    return getGameStatusBadgeClasses('finished')
  }

  return getGameStatusBadgeClasses(game?.status ?? 'pending')
}

const winnerName = (game) => getGameWinnerDisplayName(game)

const setsResult = (game) => {
  if (isByeGame(game)) {
    return null
  }

  const player1Sets = game?.sets_won?.player1
  const player2Sets = game?.sets_won?.player2

  if (typeof player1Sets === 'number' && typeof player2Sets === 'number') {
    return `${player1Sets} - ${player2Sets}`
  }

  if (!Array.isArray(game?.sets) || game.sets.length === 0) {
    return null
  }

  let player1Wins = 0
  let player2Wins = 0

  for (const currentSet of game.sets) {
    if (currentSet.player1_score > currentSet.player2_score) {
      player1Wins++
    } else if (currentSet.player2_score > currentSet.player1_score) {
      player2Wins++
    }
  }

  return `${player1Wins} - ${player2Wins}`
}

const setScoresDetail = (game) => {
  if (isByeGame(game)) {
    return []
  }

  if (!Array.isArray(game?.sets) || game.sets.length === 0) {
    return []
  }

  return [...game.sets]
    .sort((left, right) => left.set_number - right.set_number)
    .map((currentSet) => `${currentSet.player1_score}-${currentSet.player2_score}`)
}

const matchupLabel = (game) => gameMatchupLabel(game)

const participantSetsWonLabel = (game, playerNumber) => {
  const setsWon = game?.sets_won

  if (playerNumber === 1 && typeof setsWon?.player1 === 'number') {
    return String(setsWon.player1)
  }

  if (playerNumber === 2 && typeof setsWon?.player2 === 'number') {
    return String(setsWon.player2)
  }

  if (!Array.isArray(game?.sets) || game.sets.length === 0) {
    return '-'
  }

  let wins = 0

  for (const currentSet of game.sets) {
    if (playerNumber === 1 && currentSet.player1_score > currentSet.player2_score) {
      wins++
    } else if (playerNumber === 2 && currentSet.player2_score > currentSet.player1_score) {
      wins++
    }
  }

  return wins > 0 ? String(wins) : '-'
}

const gameDetailTo = (game) => ({
  path: `/games/${game.id}`,
  query: {
    competitionId: competitionId.value,
    competitionName: competition.value?.name,
    tournamentId: competition.value?.tournament_id,
  },
})

const pendingGameCardClasses =
  'space-y-1.5 rounded-md border border-slate-200 p-2.5 dark:border-slate-700 dark:bg-slate-950/30'

const finishedGameCardClasses =
  'space-y-1.5 rounded-md border border-slate-200 p-2.5 dark:border-slate-700 dark:bg-slate-950/30'

const byeGameCardClasses =
  'space-y-1 rounded-md border border-violet-200 bg-violet-50/30 p-2.5 dark:border-violet-900/60 dark:bg-violet-950/20'

const gamesAccordionSummaryClasses =
  'flex cursor-pointer list-none items-center gap-2 rounded-md px-2 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800/50 dark:hover:text-slate-100 [&::-webkit-details-marker]:hidden'

const modalGroupOptions = computed(() => {
  if (competitionGroups.value.length > 0) {
    return competitionGroups.value
  }

  if (!groupId.value) {
    return []
  }

  return [{ id: Number(groupId.value), name: groupName.value }]
})

const groupResultNav = computed(() =>
  buildGroupResultNavigation({
    games: allCompetitionGames.value,
    groupId: activeModalGroupId.value,
    gameId: selectedGame.value?.id ?? null,
    groups: modalGroupOptions.value,
  }),
)

const activeModalGroupName = computed(() => {
  const group = modalGroupOptions.value.find(
    (currentGroup) => Number(currentGroup.id) === Number(activeModalGroupId.value),
  )

  return group?.name || groupName.value
})

const modalGroupCompleteMessage = computed(() => {
  if (isRefreshingAfterSave.value || activeModalGroupId.value == null) {
    return ''
  }

  if (!isGroupScheduleComplete(allCompetitionGames.value, activeModalGroupId.value)) {
    return ''
  }

  return `✓ Todos los partidos del ${activeModalGroupName.value} están cargados.`
})

const suggestedNextGroup = computed(() => {
  if (isRefreshingAfterSave.value || !modalGroupCompleteMessage.value) {
    return null
  }

  return findNextGroupWithPendingGames(
    modalGroupOptions.value,
    allCompetitionGames.value,
    activeModalGroupId.value,
  )
})

const modalCompetitionCompleteMessage = computed(() => {
  if (isRefreshingAfterSave.value || activeModalGroupId.value == null) {
    return ''
  }

  if (
    !areAllCompetitionGroupsComplete(
      modalGroupOptions.value,
      allCompetitionGames.value,
    )
  ) {
    return ''
  }

  return '✓ Todos los grupos de la competencia están completos.'
})

const isGroupResultModalOpen = computed(
  () => Boolean(selectedGame.value) || activeModalGroupId.value != null,
)

const openResultModal = (game) => {
  selectedGame.value = game
  activeModalGroupId.value = game?.group_id ?? Number(groupId.value)
  resultNotice.clear()
  modalStatusNotice.clear()
  resultError.value = ''
}

const closeResultModal = () => {
  selectedGame.value = null
  activeModalGroupId.value = null
}

const handleModalPrevious = () => {
  const previousGame = groupResultNav.value.previousGame

  if (!previousGame) {
    return
  }

  modalStatusNotice.clear()
  selectedGame.value = previousGame
}

const handleModalNext = () => {
  const nextGame = groupResultNav.value.nextGame

  if (!nextGame) {
    return
  }

  modalStatusNotice.clear()
  selectedGame.value = nextGame
}

const handleModalGroupChange = (nextGroupId) => {
  const nextGame = selectGameWhenChangingGroup(
    allCompetitionGames.value,
    nextGroupId,
    selectedGame.value?.group_round ?? null,
  )

  modalStatusNotice.clear()
  activeModalGroupId.value = nextGroupId
  selectedGame.value = nextGame
}

const handleGoToSuggestedGroup = (groupId) => {
  const nextGame = findFirstPendingGameInGroup(allCompetitionGames.value, groupId)

  modalStatusNotice.clear()
  activeModalGroupId.value = groupId
  selectedGame.value = nextGame
}

const handleModalDirtyChange = () => {
  modalStatusNotice.clear()
}

const handleResultSaved = async (payload) => {
  const savedGame = payload?.game ?? selectedGame.value
  resultError.value = ''
  isRefreshingAfterSave.value = true

  try {
    await loadGroupSchedule({ silent: true })

    const savedGroupId = savedGame?.group_id ?? activeModalGroupId.value

    if (Number(savedGroupId) === Number(groupId.value)) {
      await loadStandings({ silent: true })
    }

    const freshSavedGame =
      findNavigableGame(allCompetitionGames.value, savedGame?.id) ?? savedGame

    if (!freshSavedGame) {
      resultNotice.show('Resultado registrado correctamente.')
      modalStatusNotice.show('Resultado registrado correctamente.')
      return
    }

    activeModalGroupId.value = freshSavedGame.group_id ?? savedGroupId
    resultNotice.show('Resultado registrado correctamente.')
    modalStatusNotice.show('Resultado registrado correctamente.')

    if (freshSavedGame.status !== 'finished') {
      selectedGame.value = freshSavedGame
      return
    }

    const nextPending = findNextPendingGame(
      allCompetitionGames.value,
      freshSavedGame.group_id ?? savedGroupId,
      freshSavedGame.group_round ?? null,
    )

    if (nextPending) {
      selectedGame.value = nextPending
      activeModalGroupId.value = nextPending.group_id
      return
    }

    selectedGame.value = freshSavedGame
  } catch (error) {
    modalStatusNotice.clear()
    resultNotice.clear()

    if (savedGame) {
      selectedGame.value = savedGame
    }

    resultError.value =
      error?.response?.data?.message || 'No se pudo actualizar la lista de partidos.'
  } finally {
    isRefreshingAfterSave.value = false
  }
}

const openAssignEntryModal = async () => {
  assignEntryNotice.clear()
  await Promise.all([loadRegistrations(), loadCompetitionGroups()])
  showAssignEntryModal.value = true
}

const refreshGroupComposition = async () => {
  await Promise.all([
    loadGroupPlayers(),
    loadStandings(),
    loadGroupSchedule(),
    loadCompetitionGroups(),
    loadRegistrations(),
    loadCompetition(),
  ])
}

const handleAssignEntrySaved = async (payload) => {
  showAssignEntryModal.value = false
  await refreshGroupComposition()
  assignEntryNotice.show(payload?.message || 'Participante agregado.')
}

const openRemoveEntryModal = (groupPlayer) => {
  closeEntryActionsMenu()
  assignEntryNotice.clear()
  selectedEntryForRemove.value = groupPlayer
  showRemoveEntryModal.value = true
}

const closeRemoveEntryModal = () => {
  showRemoveEntryModal.value = false
  selectedEntryForRemove.value = null
}

const handleRemoveEntrySaved = async (payload) => {
  closeRemoveEntryModal()
  await refreshGroupComposition()
  assignEntryNotice.show(payload?.message || 'Participante quitado del grupo.')
}

const openMoveEntryModal = async (groupPlayer) => {
  closeEntryActionsMenu()
  assignEntryNotice.clear()
  selectedEntryForMove.value = groupPlayer
  await loadCompetitionGroups()
  showMoveEntryModal.value = true
}

const closeMoveEntryModal = () => {
  showMoveEntryModal.value = false
  selectedEntryForMove.value = null
}

const handleMoveEntrySaved = async (payload) => {
  closeMoveEntryModal()
  await refreshGroupComposition()
  assignEntryNotice.show(payload?.message || 'Participante movido.')
}

const handleGenerateRoundRobin = async () => {
  isGeneratingRoundRobin.value = true
  roundRobinError.value = ''
  roundRobinNotice.clear()

  try {
    const createdItems = await GroupService.generateRoundRobin(groupId.value)
    const createdCount = createdItems.length
    await Promise.all([
      loadGroupSchedule(),
      loadStandings(),
    ])
    roundRobinNotice.show(
      isTeam.value
        ? `Todos contra todos generado. Enfrentamientos creados: ${createdCount}.`
        : `Todos contra todos generado. Partidos creados: ${createdCount}.`,
    )
  } catch (error) {
    roundRobinError.value =
      error?.response?.data?.errors?.group?.[0] ||
      error?.response?.data?.message ||
      'No se pudo generar el cuadro de todos contra todos.'
  } finally {
    isGeneratingRoundRobin.value = false
  }
}

const openPlayerStatusModal = (groupPlayer) => {
  selectedGroupEntryForStatus.value = groupPlayer
  playerStatusNotice.clear()
}

const closePlayerStatusModal = () => {
  selectedGroupEntryForStatus.value = null
}

const handlePlayerStatusSaved = async () => {
  closePlayerStatusModal()
  await Promise.all([loadGroupPlayers(), loadStandings(), loadGroupSchedule()])
  playerStatusNotice.show(`Estado del ${participantSingular(competition.value)} actualizado correctamente.`)
}

onMounted(async () => {
  await loadCompetition()
  await Promise.all([
    loadGroupPlayers(),
    loadStandings(),
    loadGroupSchedule(),
    loadCompetitionGroups(),
    loadRegistrations(),
  ])
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

    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
          {{ competition?.name ? `${competition.name} - ${groupName}` : groupName }}
        </h1>
        <p
          v-if="!isLoadingOperationalSummary"
          class="mt-1 text-sm text-slate-600 dark:text-slate-300"
        >
          {{ groupHeadline }}
        </p>
      </div>

      <AppBackButton
        class="shrink-0"
        :fallback-to="competitionId ? `/competitions/${competitionId}` : '/competitions'"
      />
    </div>

    <div
      v-if="!isLoadingOperationalSummary || (hasGroupGames && !isTeam) || hasGroupSchedule || standings.length > 0"
      class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
    >
      <p
        v-if="!isLoadingOperationalSummary"
        class="text-sm font-medium"
        :class="groupOperationalStatusClasses"
      >
        {{ groupOperationalStatus.label }}
      </p>

      <div
        v-if="(hasGroupGames && !isTeam) || hasGroupSchedule || standings.length > 0"
        class="flex flex-wrap gap-2"
      >
        <RouterLink
          v-if="hasGroupGames && !isTeam"
          :to="printGroupHref"
          :class="groupToolbarButtonClasses"
        >
          <PrinterIcon class="h-4 w-4" aria-hidden="true" />
          Imprimir grupo
        </RouterLink>

        <RouterLink
          v-if="hasGroupSchedule || standings.length > 0"
          :to="`/groups/${groupId}/standings?competitionId=${competitionId}&groupName=${encodeURIComponent(groupName)}`"
          :class="groupToolbarButtonClasses"
        >
          <ChartBarIcon class="h-4 w-4" aria-hidden="true" />
          Ver posiciones
        </RouterLink>
      </div>
    </div>

    <p
      v-if="compositionLockMessage"
      class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300"
    >
      {{ compositionLockMessage }}
    </p>

    <div class="overflow-hidden rounded-md border border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-900">
      <div class="flex items-start justify-between gap-3 px-4 py-3">
        <div class="min-w-0">
          <p class="font-medium text-slate-900 dark:text-slate-100">{{ groupPlayersTitle }}</p>
          <p
            v-if="!isLoadingGroupPlayers && !isLoadingStandings"
            class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
          >
            {{ groupPlayersCountLabel }}
            <template v-if="standings.length > 0">
              · clasifican los primeros {{ qualifiedPerGroup }}
            </template>
          </p>
        </div>

        <button
          v-if="canAddGroupEntry"
          type="button"
          class="inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
          @click="openAssignEntryModal"
        >
          <PlusIcon class="h-4 w-4" aria-hidden="true" />
          {{ addGroupEntryLabel }}
        </button>
      </div>

      <div class="border-t border-slate-200 dark:border-slate-700">
        <div
          v-if="assignEntryNotice.message || playerStatusNotice.message"
          class="space-y-3 px-4 py-3"
        >
          <p v-if="assignEntryNotice.message" class="text-emerald-700 dark:text-emerald-300">
            {{ assignEntryNotice.message }}
          </p>
          <p v-if="playerStatusNotice.message" class="text-emerald-700 dark:text-emerald-300">
            {{ playerStatusNotice.message }}
          </p>
        </div>

        <p
          v-if="isLoadingGroupPlayers || isLoadingStandings"
          class="px-4 py-3 text-slate-600 dark:text-slate-300"
        >
          Cargando {{ isTeam ? 'equipos' : participantPlural(competition) }} del grupo...
        </p>
        <p v-else-if="groupPlayersError" class="px-4 py-3 text-red-600 dark:text-red-400">
          {{ groupPlayersError }}
        </p>
        <p
          v-else-if="groupPlayers.length === 0"
          class="px-4 py-3 text-slate-600 dark:text-slate-300"
        >
          {{ emptyGroupMembersMessage }}
        </p>

        <div v-else>
          <GroupStandingsTable
            v-if="standingRows.length > 0"
            :rows="standingRows"
            :is-team="isTeam"
            embedded
            :qualifier-cutoff="qualifiedPerGroup"
          >
            <template #after-name="{ row }">
              <p
                v-if="groupPlayerNickname(groupPlayerForRow(row))"
                class="mt-0.5 text-xs font-normal text-slate-500 dark:text-slate-400"
              >
                {{ groupPlayerNickname(groupPlayerForRow(row)) }}
              </p>
              <p
                v-if="groupPlayerForRow(row)?.status_notes"
                class="mt-0.5 text-xs font-normal text-slate-500 dark:text-slate-400"
              >
                Notas: {{ groupPlayerForRow(row).status_notes }}
              </p>
            </template>

            <template v-if="canManageGroups || canEditGroupComposition" #actions="{ row }">
              <button
                v-if="groupPlayerForRow(row) && canChangePlayerStatus(groupPlayerForRow(row)) && canManageGroups"
                type="button"
                class="whitespace-nowrap rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                aria-label="Retirar o descalificar"
                @click="openPlayerStatusModal(groupPlayerForRow(row))"
              >
                <span class="sm:hidden">Baja</span>
                <span class="hidden sm:inline">Retirar / descalificar</span>
              </button>

              <GroupEntryActionsMenu
                v-if="groupPlayerForRow(row) && canEditGroupComposition"
                :open="Number(openEntryActionsId) === Number(groupPlayerForRow(row).competition_entry_id)"
                :can-edit="isGroupEntryEditable(groupPlayerForRow(row))"
                :has-move-targets="hasMoveTargets"
                :lock-message="entryActionsDisabledMessage(groupPlayerForRow(row))"
                :move-label="moveGroupEntryLabel"
                :remove-label="removeGroupEntryLabel"
                :menu-label="entryActionsMenuLabel(groupPlayerForRow(row))"
                @toggle="toggleEntryActionsMenu(groupPlayerForRow(row))"
                @close="closeEntryActionsMenu"
                @move="openMoveEntryModal(groupPlayerForRow(row))"
                @remove="openRemoveEntryModal(groupPlayerForRow(row))"
              />
            </template>
          </GroupStandingsTable>

          <div
            v-if="managementGroupPlayers.length > 0"
            class="space-y-1.5 px-4 py-3"
            :class="standingRows.length > 0 ? 'border-t border-slate-200 dark:border-slate-700' : ''"
          >
            <p
              v-if="standingRows.length > 0"
              class="text-xs text-slate-500 dark:text-slate-400"
            >
              Participaciones que todavía no figuran en la tabla.
            </p>

            <article
              v-for="groupPlayer in managementGroupPlayers"
              :key="groupPlayer.id"
              class="rounded border border-slate-200 px-3 py-2 dark:border-slate-700"
              :class="!isPlayerActive(groupPlayer) ? 'bg-slate-50/60 opacity-80 dark:bg-slate-800/40' : ''"
            >
              <div class="flex items-start gap-x-2 gap-y-1">
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                  <p class="font-medium text-slate-900 dark:text-slate-100">
                    {{ groupEntryDisplayName(groupPlayer) }}
                  </p>

                  <span
                    v-if="playerStatusLabel(groupPlayer)"
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="playerStatusBadgeClasses(groupPlayer.status)"
                  >
                    {{ playerStatusLabel(groupPlayer) }}
                  </span>
                </div>

                <div
                  v-if="(canChangePlayerStatus(groupPlayer) && canManageGroups) || canEditGroupComposition"
                  class="ml-auto flex shrink-0 items-center gap-1"
                >
                  <button
                    v-if="canChangePlayerStatus(groupPlayer) && canManageGroups"
                    type="button"
                    class="whitespace-nowrap rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                    aria-label="Retirar o descalificar"
                    @click="openPlayerStatusModal(groupPlayer)"
                  >
                    <span class="sm:hidden">Baja</span>
                    <span class="hidden sm:inline">Retirar / descalificar</span>
                  </button>

                  <GroupEntryActionsMenu
                    v-if="canEditGroupComposition"
                    :open="Number(openEntryActionsId) === Number(groupPlayer.competition_entry_id)"
                    :can-edit="isGroupEntryEditable(groupPlayer)"
                    :has-move-targets="hasMoveTargets"
                    :lock-message="entryActionsDisabledMessage(groupPlayer)"
                    :move-label="moveGroupEntryLabel"
                    :remove-label="removeGroupEntryLabel"
                    :menu-label="entryActionsMenuLabel(groupPlayer)"
                    @toggle="toggleEntryActionsMenu(groupPlayer)"
                    @close="closeEntryActionsMenu"
                    @move="openMoveEntryModal(groupPlayer)"
                    @remove="openRemoveEntryModal(groupPlayer)"
                  />
                </div>
              </div>

              <p
                v-if="isMultiMember && groupPlayer.members?.length"
                class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
              >
                {{
                  groupPlayer.members
                    .map((member) => `${member.first_name} ${member.last_name}`.trim())
                    .join(' · ')
                }}
              </p>
              <p
                v-else-if="groupPlayerNickname(groupPlayer)"
                class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
              >
                {{ groupPlayerNickname(groupPlayer) }}
              </p>
              <p
                v-if="groupPlayer.status_notes"
                class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
              >
                Notas: {{ groupPlayer.status_notes }}
              </p>
            </article>
          </div>
        </div>
      </div>
    </div>

    <div
      class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="font-medium text-slate-900 dark:text-slate-100">{{ scheduleSectionTitle }}</p>
          <p
            v-if="hasGroupSchedule && !isLoadingOperationalSummary"
            class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
          >
            {{ scheduleHeadline }}
          </p>
        </div>

        <button
          v-if="canShowGenerateRoundRobin"
          type="button"
          class="shrink-0 rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-emerald-600 dark:hover:bg-emerald-500"
          :disabled="isGeneratingRoundRobin"
          @click="handleGenerateRoundRobin"
        >
          {{ isGeneratingRoundRobin ? 'Generando...' : 'Generar todos contra todos' }}
        </button>
      </div>

      <p
        v-if="!hasGroupSchedule && isTeam && !hasTeamTieFormat"
        class="rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
      >
        Configurá un formato de enfrentamiento en la competencia antes de generar el calendario.
      </p>

      <p v-if="roundRobinError" class="text-red-600 dark:text-red-400">{{ roundRobinError }}</p>
      <p v-if="roundRobinNotice.message" class="text-emerald-700 dark:text-emerald-300">
        {{ roundRobinNotice.message }}
      </p>
      <p v-if="resultNotice.message && !isTeam" class="text-emerald-700 dark:text-emerald-300">
        {{ resultNotice.message }}
      </p>
      <p v-if="resultError && !isTeam" class="text-red-600 dark:text-red-400">{{ resultError }}</p>

      <template v-if="isTeam">
        <p v-if="isLoadingTeamTies" class="text-slate-600 dark:text-slate-300">
          Cargando enfrentamientos...
        </p>
        <p v-else-if="teamTiesError" class="text-red-600 dark:text-red-400">{{ teamTiesError }}</p>

        <div
          v-else-if="!hasGroupTeamTies"
          class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300"
        >
          Todavía no hay enfrentamientos generados para este grupo.
        </div>

        <div v-else class="space-y-4">
          <details
            v-for="section in teamTieRoundSections"
            :key="roundSectionKey(section)"
            :open="isRoundSectionOpen(section)"
            class="group/round overflow-hidden rounded-md border border-slate-200 dark:border-slate-700"
            @toggle="onRoundToggle(section, $event)"
          >
            <summary :class="gamesAccordionSummaryClasses">
              <span class="min-w-0 flex-1">
                <span class="block text-slate-800 dark:text-slate-100">{{ section.label }}</span>
                <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs font-normal text-slate-500 dark:text-slate-400">
                  <span>{{ section.countLabel }}</span>
                  <span aria-hidden="true">·</span>
                  <span
                    class="inline-flex rounded-full px-2 py-0.5 font-medium"
                    :class="roundStatusChipClasses(section)"
                  >
                    {{ section.statusLabel }}
                  </span>
                </span>
              </span>
              <ChevronDownIcon
                class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 group-open/round:rotate-180"
                aria-hidden="true"
              />
            </summary>

            <ul class="space-y-2 border-t border-slate-200 px-1 pb-1 pt-2 dark:border-slate-700">
              <li
                v-for="teamTie in section.items"
                :key="`team-tie-${teamTie.id}`"
                :class="pendingGameCardClasses"
              >
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                  <p class="min-w-0 flex-1 font-medium text-slate-900 dark:text-slate-100">
                    {{ teamTieMatchupLabel(teamTie) }}
                  </p>

                  <span
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="getTeamTieStatusBadgeClasses(teamTie.status)"
                  >
                    {{ getTeamTieStatusLabel(teamTie.status) }}
                  </span>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400">
                  {{ teamTie.format?.name }} · gana con {{ teamTie.format?.victories_required }}
                </p>

                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                  <div class="text-sm text-slate-700 dark:text-slate-200">
                    <p
                      class="font-medium"
                      :class="teamTie.status === 'finished' ? 'text-emerald-800 dark:text-emerald-200' : ''"
                    >
                      {{ teamTieScoreLabel(teamTie) }}
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                      {{ teamTieLineupSummary(teamTie) }}
                    </p>
                  </div>

                  <RouterLink
                    :to="`/team-ties/${teamTie.id}`"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                  >
                    Ver enfrentamiento
                  </RouterLink>
                </div>
              </li>
            </ul>
          </details>
        </div>
      </template>

      <template v-else>
      <p v-if="isLoadingGames" class="text-slate-600 dark:text-slate-300">Cargando partidos...</p>
      <p v-else-if="gamesError" class="text-red-600 dark:text-red-400">{{ gamesError }}</p>

      <div
        v-else-if="!hasGroupGames"
        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300"
      >
        Todavía no hay partidos generados para este grupo.
      </div>

      <div v-else class="space-y-4">
        <details
          v-for="section in gameRoundSections"
          :key="roundSectionKey(section)"
          :open="isRoundSectionOpen(section)"
          class="group/round overflow-hidden rounded-md border border-slate-200 dark:border-slate-700"
          @toggle="onRoundToggle(section, $event)"
        >
          <summary :class="gamesAccordionSummaryClasses">
            <span class="min-w-0 flex-1">
              <span class="block text-slate-800 dark:text-slate-100">{{ section.label }}</span>
              <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs font-normal text-slate-500 dark:text-slate-400">
                <span>{{ section.countLabel }}</span>
                <span aria-hidden="true">·</span>
                <span
                  class="inline-flex rounded-full px-2 py-0.5 font-medium"
                  :class="roundStatusChipClasses(section)"
                >
                  {{ section.statusLabel }}
                </span>
              </span>
            </span>
            <ChevronDownIcon
              class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 group-open/round:rotate-180"
              aria-hidden="true"
            />
          </summary>

          <ul class="space-y-2 border-t border-slate-200 px-1 pb-1 pt-2 dark:border-slate-700">
            <li
              v-for="game in section.items"
              :key="`round-game-${game.id}`"
              :class="isFinishedGame(game) ? finishedGameCardClasses : pendingGameCardClasses"
            >
              <template v-if="isFinishedGame(game)">
                <p class="font-medium text-slate-900 dark:text-slate-100">
                  {{ matchupLabel(game) }}
                </p>

                <div class="flex flex-wrap items-center gap-2 text-xs">
                  <span
                    class="inline-flex rounded-full px-2 py-0.5 font-medium"
                    :class="statusBadgeClasses(game)"
                  >
                    {{ statusLabel(game) }}
                  </span>

                  <span v-if="setsResult(game)" class="text-slate-600 dark:text-slate-300">
                    Resultado: {{ setsResult(game) }}
                  </span>
                </div>

                <p v-if="setScoresDetail(game).length > 0" class="text-xs text-slate-600 dark:text-slate-300">
                  Detalle: {{ setScoresDetail(game).join(', ') }}
                </p>

                <p class="text-xs text-slate-600 dark:text-slate-300">
                  Ganador: {{ winnerName(game) }}
                </p>

                <RouterLink
                  :to="gameDetailTo(game)"
                  class="inline-flex text-xs font-medium text-slate-700 hover:underline dark:text-slate-300"
                >
                  Ver detalle
                </RouterLink>
              </template>

              <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                  <p class="min-w-0 flex-1 font-medium text-slate-900 dark:text-slate-100">
                    {{ matchupLabel(game) }}
                  </p>

                  <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                    <span
                      class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                      :class="statusBadgeClasses(game)"
                    >
                      {{ statusLabel(game) }}
                    </span>

                    <button
                      v-if="canRecordResults && canLoadResult(game)"
                      type="button"
                      class="rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-600"
                      @click="openResultModal(game)"
                    >
                      Cargar resultado
                    </button>
                  </div>
                </div>

                <p v-if="matchFormatLabel(game)" class="text-xs text-slate-500 dark:text-slate-400">
                  {{ matchFormatLabel(game) }}
                </p>

                <div
                  v-if="game.status === 'in_progress'"
                  class="overflow-hidden rounded border border-slate-200 dark:border-slate-700"
                >
                  <div class="flex items-center justify-between gap-2 px-2 py-1.5">
                    <span class="truncate text-sm text-slate-900 dark:text-slate-100">
                      {{ getGameSideDisplayName(game, 1) }}
                    </span>
                    <span class="shrink-0 tabular-nums text-sm text-slate-700 dark:text-slate-300">
                      {{ participantSetsWonLabel(game, 1) }}
                    </span>
                  </div>
                  <div
                    class="flex items-center justify-between gap-2 border-t border-slate-200 px-2 py-1.5 dark:border-slate-700"
                  >
                    <span class="truncate text-sm text-slate-900 dark:text-slate-100">
                      {{ getGameSideDisplayName(game, 2) }}
                    </span>
                    <span class="shrink-0 tabular-nums text-sm text-slate-700 dark:text-slate-300">
                      {{ participantSetsWonLabel(game, 2) }}
                    </span>
                  </div>
                </div>

                <p
                  v-if="setScoresDetail(game).length > 0"
                  class="text-xs text-slate-600 dark:text-slate-300"
                >
                  Parcial: {{ setScoresDetail(game).join(', ') }}
                </p>
              </template>
            </li>
          </ul>
        </details>

        <details
          v-if="byeGames.length > 0"
          class="group/bye overflow-hidden rounded-md border border-violet-200 dark:border-violet-900/60"
        >
          <summary :class="gamesAccordionSummaryClasses">
            <span class="flex-1">Avances automáticos ({{ byeGames.length }})</span>
            <ChevronDownIcon
              class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 group-open/bye:rotate-180"
              aria-hidden="true"
            />
          </summary>

          <ul class="space-y-2 border-t border-violet-200 px-1 pb-1 pt-2 dark:border-violet-900/60">
            <li
              v-for="game in byeGames"
              :key="`bye-${game.id}`"
              :class="byeGameCardClasses"
            >
              <div class="flex flex-wrap items-center gap-2">
                <p class="font-medium text-slate-900 dark:text-slate-100">
                  {{ getGameSideDisplayName(game, 1) }}
                </p>

                <span
                  class="inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800 dark:bg-violet-900/60 dark:text-violet-200"
                >
                  BYE
                </span>

                <span
                  class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="statusBadgeClasses(game)"
                >
                  {{ statusLabel(game) }}
                </span>
              </div>

              <p class="text-xs text-slate-600 dark:text-slate-300">
                Avanza: {{ winnerName(game) }}
              </p>
            </li>
          </ul>
        </details>
      </div>
      </template>
    </div>

    <GameResultModal
      v-if="!isTeam"
      :show="isGroupResultModalOpen"
      :game="selectedGame"
      :show-group-navigation="true"
      :groups="groupResultNav.groups"
      :selected-group-id="activeModalGroupId"
      :round-label="groupResultNav.roundLabel"
      :match-label="groupResultNav.matchLabel"
      :can-go-previous="groupResultNav.canGoPrevious"
      :can-go-next="groupResultNav.canGoNext"
      :group-complete-message="modalGroupCompleteMessage"
      :competition-complete-message="modalCompetitionCompleteMessage"
      :next-group="suggestedNextGroup"
      :status-message="modalStatusNotice.message"
      :is-busy="isRefreshingAfterSave"
      @close="closeResultModal"
      @saved="handleResultSaved"
      @previous="handleModalPrevious"
      @next="handleModalNext"
      @change-group="handleModalGroupChange"
      @go-to-group="handleGoToSuggestedGroup"
      @dirty-change="handleModalDirtyChange"
    />

    <AssignGroupEntryModal
      :show="showAssignEntryModal"
      :group-id="groupId"
      :group-name="groupName"
      :competition="competition"
      :available-entries="availableEntries"
      :current-member-count="groupPlayers.length"
      @close="showAssignEntryModal = false"
      @saved="handleAssignEntrySaved"
    />

    <RemoveGroupEntryModal
      :show="showRemoveEntryModal"
      :group-id="groupId"
      :group-name="groupName"
      :competition="competition"
      :group-entry="selectedEntryForRemove"
      @close="closeRemoveEntryModal"
      @saved="handleRemoveEntrySaved"
    />

    <MoveGroupEntryModal
      :show="showMoveEntryModal"
      :source-group-id="groupId"
      :competition="competition"
      :group-entry="selectedEntryForMove"
      :target-groups="moveTargetGroups"
      @close="closeMoveEntryModal"
      @saved="handleMoveEntrySaved"
    />

    <GroupPlayerStatusModal
      :show="Boolean(selectedGroupEntryForStatus)"
      :group-id="groupId"
      :group-entry="selectedGroupEntryForStatus"
      :is-multi-member="isMultiMember"
      @close="closePlayerStatusModal"
      @saved="handlePlayerStatusSaved"
    />
  </section>
</template>
