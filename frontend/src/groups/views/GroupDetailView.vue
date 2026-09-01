<script setup>
import { ChevronDownIcon, UserGroupIcon } from '@heroicons/vue/24/outline'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import { usePermissions } from '../../composables/usePermissions'
import BracketService from '../../brackets/services/BracketService'
import CompetitionService from '../../competitions/services/CompetitionService'
import { structureLockReason } from '../../competitions/utils/competitionStructure'
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
import {
  getQualificationBadgeClasses,
  getQualificationCardClasses,
  getQualificationIcon,
  getQualificationLabel,
  resolveGroupQualification,
} from '../../standings/utils/resolveGroupQualification'
import GroupPlayerStatusModal from '../components/GroupPlayerStatusModal.vue'
import { getGroupPlayerStatusLabel } from '../constants/groupPlayerStatus'
import GroupService from '../services/GroupService'
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

const competitionStructureLockReason = computed(() => structureLockReason(competition.value))

const groupPlayers = ref([])
const isLoadingGroupPlayers = ref(false)
const groupPlayersError = ref('')

const standings = ref([])
const standingsMeta = ref({})
const isLoadingStandings = ref(false)

const isGeneratingRoundRobin = ref(false)
const roundRobinError = ref('')
const roundRobinSuccessMessage = ref('')

const selectedGroupEntryForStatus = ref(null)
const playerStatusSuccessMessage = ref('')

const standingsAreProvisional = computed(() => Boolean(standingsMeta.value.standings_are_provisional))

const pendingManualTiebreakGroups = computed(() => standingsMeta.value.manual_tiebreak_groups ?? [])

const requiresManualTiebreak = computed(
  () => !standingsAreProvisional.value && Boolean(standingsMeta.value.requires_manual_tiebreak),
)

const buildQualification = (standing, position) =>
  resolveGroupQualification({
    standing,
    position,
    qualifiedPerGroup: qualifiedPerGroup.value,
    standingsAreProvisional: standingsAreProvisional.value,
    requiresManualTiebreak: requiresManualTiebreak.value,
    manualTiebreakGroups: pendingManualTiebreakGroups.value,
    allStandings: standings.value,
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

const displayedGroupPlayers = computed(() => {
  if (standings.value.length === 0) {
    return groupPlayers.value.map((groupPlayer) => ({
      groupPlayer,
      position: null,
      qualification: null,
    }))
  }

  const displayed = standings.value
    .map((standing, index) => {
      const groupPlayer = groupPlayers.value.find(
        (currentGroupPlayer) =>
          currentGroupPlayer.competition_entry_id === standing.competition_entry_id,
      )

      if (!groupPlayer) {
        return null
      }

      const position = index + 1

      return {
        groupPlayer,
        position,
        qualification: buildQualification(standing, position),
      }
    })
    .filter(Boolean)

  const displayedEntryIds = new Set(
    displayed.map((entry) => entry.groupPlayer.competition_entry_id).filter(Boolean),
  )

  for (const groupPlayer of groupPlayers.value) {
    if (!displayedEntryIds.has(groupPlayer.competition_entry_id)) {
      displayed.push({
        groupPlayer,
        position: null,
        qualification: null,
      })
    }
  }

  return displayed
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

const playerCardClasses = (entry) =>
  getQualificationCardClasses(entry.qualification?.kind, {
    isInactive: !isPlayerActive(entry.groupPlayer),
  })

const qualificationBadgeClasses = (qualification) => getQualificationBadgeClasses(qualification?.kind)

const qualificationLabel = (qualification) => getQualificationLabel(qualification?.kind)

const qualificationIcon = (qualification) => getQualificationIcon(qualification?.kind)

const groupPlayersAccordionSummaryClasses =
  'flex cursor-pointer list-none items-center gap-3 rounded-md p-4 text-sm transition hover:bg-slate-50 dark:hover:bg-slate-800/50 [&::-webkit-details-marker]:hidden'

const groupPlayersAccordionIconContainerClasses =
  'flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 ring-1 ring-slate-200 dark:bg-slate-800/80 dark:ring-slate-600'

const groupPlayersAccordionIconClasses =
  'h-5 w-5 text-slate-600 dark:text-slate-300'

const groupPlayersCount = computed(() => displayedGroupPlayers.value.length)

const participantsSummaryLabel = computed(() => {
  const label = participantPlural(competition.value)

  return label.charAt(0).toUpperCase() + label.slice(1)
})

const assignedParticipantsEmptyLabel = computed(() => {
  const label = participantPlural(competition.value)

  return `${label} asignados`
})

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

const loadStandings = async () => {
  isLoadingStandings.value = true

  try {
    const { standings: groupStandings, meta } = await StandingService.listByGroup(groupId.value)
    standings.value = groupStandings
    standingsMeta.value = meta
  } catch {
    standings.value = []
    standingsMeta.value = {}
  } finally {
    isLoadingStandings.value = false
  }
}

const games = ref([])
const teamTies = ref([])
const isLoadingGames = ref(false)
const isLoadingTeamTies = ref(false)
const gamesError = ref('')
const teamTiesError = ref('')
const selectedGame = ref(null)
const resultSuccessMessage = ref('')
const resultError = ref('')

const hasGroupGames = computed(() => games.value.length > 0)
const hasGroupTeamTies = computed(() => teamTies.value.length > 0)
const hasGroupSchedule = computed(() =>
  isTeam.value ? hasGroupTeamTies.value : hasGroupGames.value,
)

const scheduleSectionTitle = computed(() =>
  isTeam.value ? 'Enfrentamientos del grupo' : 'Partidos del grupo',
)

const isLoadingOperationalSummary = computed(
  () =>
    isLoadingGroupPlayers.value ||
    isLoadingStandings.value ||
    (isTeam.value ? isLoadingTeamTies.value : isLoadingGames.value),
)

const groupPlayersTitle = computed(() => {
  if (standings.value.length > 0) {
    return 'Posiciones del grupo'
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

const loadGroupSchedule = async () => {
  if (isTeam.value) {
    await loadGroupTeamTies()
    games.value = []
    return
  }

  await loadGroupGames()
  teamTies.value = []
}

const loadGroupGames = async () => {
  if (!competitionId.value || !groupId.value) {
    games.value = []
    return
  }

  isLoadingGames.value = true
  gamesError.value = ''

  try {
    const allGames = await GameService.listByCompetition(competitionId.value)

    games.value = allGames.filter(
      (game) => Number(game.group_id) === Number(groupId.value),
    )
  } catch (error) {
    games.value = []
    gamesError.value =
      error?.response?.data?.message || 'No se pudo cargar los partidos del grupo.'
  } finally {
    isLoadingGames.value = false
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

const teamTiesByRound = computed(() => {
  const rounds = new Map()

  for (const teamTie of [...teamTies.value].sort(compareByGroupSchedule)) {
    const roundNumber = teamTie.group_round

    if (roundNumber == null) {
      continue
    }

    if (!rounds.has(roundNumber)) {
      rounds.set(roundNumber, [])
    }

    rounds.get(roundNumber).push(teamTie)
  }

  return [...rounds.entries()]
    .sort(([leftRound], [rightRound]) => leftRound - rightRound)
    .map(([roundNumber, roundTeamTies]) => ({
      roundNumber,
      label: `Ronda ${roundNumber}`,
      teamTies: roundTeamTies,
    }))
})

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

const scheduleCountLabel = computed(() => (isTeam.value ? 'Enfrentamientos' : 'Partidos'))

const pendingLoadGames = computed(() =>
  games.value.filter(canLoadResult).sort(compareByGroupSchedule),
)

const pendingGamesByRound = computed(() => {
  const rounds = new Map()
  const legacy = []

  for (const game of pendingLoadGames.value) {
    if (game.group_round == null) {
      legacy.push(game)
      continue
    }

    const roundNumber = game.group_round

    if (!rounds.has(roundNumber)) {
      rounds.set(roundNumber, [])
    }

    rounds.get(roundNumber).push(game)
  }

  const grouped = [...rounds.entries()]
    .sort(([leftRound], [rightRound]) => leftRound - rightRound)
    .map(([roundNumber, roundGames]) => ({
      roundNumber,
      label: `Ronda ${roundNumber}`,
      games: roundGames,
    }))

  if (legacy.length > 0) {
    grouped.push({
      roundNumber: null,
      label: 'Sin ronda asignada',
      games: legacy,
    })
  }

  return grouped
})

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
  'flex cursor-pointer list-none items-center gap-2 rounded-md py-1 text-sm font-medium text-slate-700 transition hover:text-slate-900 dark:text-slate-300 dark:hover:text-slate-100 [&::-webkit-details-marker]:hidden'

const openResultModal = (game) => {
  selectedGame.value = game
  resultSuccessMessage.value = ''
  resultError.value = ''
}

const closeResultModal = () => {
  selectedGame.value = null
}

const handleResultSaved = async () => {
  closeResultModal()
  resultError.value = ''

  try {
    await Promise.all([loadGroupSchedule(), loadStandings()])
    resultSuccessMessage.value = 'Resultado registrado correctamente.'
  } catch (error) {
    resultError.value =
      error?.response?.data?.message || 'No se pudo actualizar la lista de partidos.'
  }
}

const handleGenerateRoundRobin = async () => {
  isGeneratingRoundRobin.value = true
  roundRobinError.value = ''
  roundRobinSuccessMessage.value = ''

  try {
    const createdItems = await GroupService.generateRoundRobin(groupId.value)
    const createdCount = createdItems.length
    roundRobinSuccessMessage.value = isTeam.value
      ? `Todos contra todos generado. Enfrentamientos creados: ${createdCount}.`
      : `Todos contra todos generado. Partidos creados: ${createdCount}.`
    await Promise.all([
      loadGroupSchedule(),
      loadStandings(),
    ])
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
  playerStatusSuccessMessage.value = ''
}

const closePlayerStatusModal = () => {
  selectedGroupEntryForStatus.value = null
}

const handlePlayerStatusSaved = async () => {
  closePlayerStatusModal()
  await Promise.all([loadGroupPlayers(), loadStandings(), loadGroupSchedule()])
  playerStatusSuccessMessage.value = `Estado del ${participantSingular(competition.value)} actualizado correctamente.`
}

onMounted(async () => {
  await loadCompetition()
  await Promise.all([
    loadGroupPlayers(),
    loadStandings(),
    loadGroupSchedule(),
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

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ competition?.name ? `${competition.name} - ${groupName}` : groupName }}
      </h1>

      <div class="flex items-center gap-3">
        <AppBackButton :fallback-to="competitionId ? `/competitions/${competitionId}` : '/competitions'" />

        <RouterLink
          v-if="hasGroupSchedule || standings.length > 0"
          :to="`/groups/${groupId}/standings?competitionId=${competitionId}&groupName=${encodeURIComponent(groupName)}`"
          class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-300"
        >
          Ver posiciones
        </RouterLink>
      </div>
    </div>

    <p
      v-if="competitionStructureLockReason"
      class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
    >
      {{ competitionStructureLockReason }}
    </p>

    <div
      class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
    >
      <p class="font-medium text-slate-700 dark:text-slate-200">Resumen operativo</p>

      <p v-if="isLoadingOperationalSummary" class="mt-2 text-slate-600 dark:text-slate-300">
        Cargando resumen...
      </p>

      <dl v-else class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div>
          <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ participantsSummaryLabel }}</dt>
          <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ groupPlayersCount }}</dd>
        </div>

        <div>
          <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ scheduleCountLabel }}</dt>
          <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ scheduleCount }}</dd>
        </div>

        <div>
          <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Pendientes</dt>
          <dd class="mt-1 font-semibold text-amber-800 dark:text-amber-200">
            {{ pendingScheduleCount }}
          </dd>
        </div>

        <div>
          <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Finalizados</dt>
          <dd class="mt-1 font-semibold text-emerald-800 dark:text-emerald-200">
            {{ finishedScheduleCount }}
          </dd>
        </div>

        <div v-if="!isTeam">
          <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
            Clasifican por grupo
          </dt>
          <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ qualifiedPerGroup }}</dd>
        </div>
      </dl>
    </div>

    <details
      class="group/players overflow-hidden rounded-md border border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-900"
    >
      <summary :class="groupPlayersAccordionSummaryClasses">
        <span :class="groupPlayersAccordionIconContainerClasses">
          <UserGroupIcon :class="groupPlayersAccordionIconClasses" />
        </span>

        <div class="min-w-0 flex-1">
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

        <ChevronDownIcon
          class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200 group-open/players:rotate-180"
          aria-hidden="true"
        />
      </summary>

      <div class="space-y-3 border-t border-slate-200 px-4 pb-4 pt-3 dark:border-slate-700">
        <p v-if="playerStatusSuccessMessage" class="text-emerald-700 dark:text-emerald-300">
          {{ playerStatusSuccessMessage }}
        </p>

        <p v-if="isLoadingGroupPlayers || isLoadingStandings" class="text-slate-600 dark:text-slate-300">
          Cargando {{ isTeam ? 'equipos' : participantPlural(competition) }} del grupo...
        </p>
        <p v-else-if="groupPlayersError" class="text-red-600 dark:text-red-400">{{ groupPlayersError }}</p>

        <div
          v-else-if="groupPlayers.length === 0"
          class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300"
        >
          Este grupo todavía no tiene {{ assignedParticipantsEmptyLabel }}.
        </div>

        <div v-else class="space-y-1.5">
          <article
            v-for="entry in displayedGroupPlayers"
            :key="entry.groupPlayer.id"
            class="rounded border px-3 py-2"
            :class="playerCardClasses(entry)"
          >
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span
                v-if="entry.position"
                class="inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold"
                :class="positionBadgeClasses(entry.position)"
              >
                {{ entry.position }}°
              </span>

              <p class="font-medium text-slate-900 dark:text-slate-100">
                {{ groupEntryDisplayName(entry.groupPlayer) }}
              </p>

              <span
                v-if="playerStatusLabel(entry.groupPlayer)"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="playerStatusBadgeClasses(entry.groupPlayer.status)"
              >
                {{ playerStatusLabel(entry.groupPlayer) }}
              </span>

              <span
                v-if="entry.qualification"
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                :class="qualificationBadgeClasses(entry.qualification)"
              >
                <span v-if="qualificationIcon(entry.qualification)" aria-hidden="true">
                  {{ qualificationIcon(entry.qualification) }}
                </span>
                {{ qualificationLabel(entry.qualification) }}
              </span>

              <button
                v-if="canChangePlayerStatus(entry.groupPlayer) && canManageGroups"
                type="button"
                class="ml-auto rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                @click="openPlayerStatusModal(entry.groupPlayer)"
              >
                Retirar / descalificar
              </button>
            </div>

            <p
              v-if="isMultiMember && entry.groupPlayer.members?.length"
              class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
            >
              {{
                entry.groupPlayer.members
                  .map((member) => `${member.first_name} ${member.last_name}`.trim())
                  .join(' · ')
              }}
            </p>
            <p
              v-else-if="entry.groupPlayer.player?.nickname"
              class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
            >
              {{ entry.groupPlayer.player.nickname }}
            </p>
            <p
              v-if="entry.groupPlayer.status_notes"
              class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
            >
              Notas: {{ entry.groupPlayer.status_notes }}
            </p>
          </article>
        </div>
      </div>
    </details>

    <div
      class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
    >
      <div class="flex items-center justify-between gap-3">
        <p class="font-medium text-slate-700 dark:text-slate-200">{{ scheduleSectionTitle }}</p>

        <button
          v-if="!hasGroupSchedule && canManageGroups && canGenerateRoundRobin"
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
      <p v-if="roundRobinSuccessMessage" class="text-emerald-700 dark:text-emerald-300">
        {{ roundRobinSuccessMessage }}
      </p>
      <p v-if="resultSuccessMessage && !isTeam" class="text-emerald-700 dark:text-emerald-300">
        {{ resultSuccessMessage }}
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
            v-for="(roundGroup, roundIndex) in teamTiesByRound"
            :key="roundGroup.roundNumber"
            :open="roundIndex === 0"
            class="group/team-round overflow-hidden rounded-md border border-slate-200 dark:border-slate-700"
          >
            <summary :class="gamesAccordionSummaryClasses">
              <span class="flex-1">
                {{ roundGroup.label }} ({{ roundGroup.teamTies.length }})
              </span>
              <ChevronDownIcon
                class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 group-open/team-round:rotate-180"
                aria-hidden="true"
              />
            </summary>

            <ul class="space-y-2 border-t border-slate-200 px-1 pb-1 pt-2 dark:border-slate-700">
              <li
                v-for="teamTie in roundGroup.teamTies"
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
        <section v-if="pendingLoadGames.length > 0" class="space-y-3">
          <h2 class="font-medium text-slate-800 dark:text-slate-200">
            Pendientes de carga ({{ pendingLoadGames.length }})
          </h2>

          <details
            v-for="(roundGroup, roundIndex) in pendingGamesByRound"
            :key="roundGroup.roundNumber ?? 'legacy'"
            :open="roundIndex === 0"
            class="group/pending-round overflow-hidden rounded-md border border-slate-200 dark:border-slate-700"
          >
            <summary :class="gamesAccordionSummaryClasses">
              <span class="flex-1">
                {{ roundGroup.label }} ({{ roundGroup.games.length }})
              </span>
              <ChevronDownIcon
                class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 group-open/pending-round:rotate-180"
                aria-hidden="true"
              />
            </summary>

            <ul class="space-y-2 border-t border-slate-200 px-1 pb-1 pt-2 dark:border-slate-700">
              <li
                v-for="game in roundGroup.games"
                :key="`pending-${game.id}`"
                :class="pendingGameCardClasses"
              >
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
                      v-if="canRecordResults"
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
              </li>
            </ul>
          </details>
        </section>

        <details
          v-if="finishedGames.length > 0"
          class="group/finished overflow-hidden rounded-md border border-slate-200 dark:border-slate-700"
        >
          <summary :class="gamesAccordionSummaryClasses">
            <span class="flex-1">Finalizados ({{ finishedGames.length }})</span>
            <ChevronDownIcon
              class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 group-open/finished:rotate-180"
              aria-hidden="true"
            />
          </summary>

          <ul class="space-y-2 border-t border-slate-200 px-1 pb-1 pt-2 dark:border-slate-700">
            <li
              v-for="game in finishedGames"
              :key="`finished-${game.id}`"
              :class="finishedGameCardClasses"
            >
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
      :show="Boolean(selectedGame)"
      :game="selectedGame"
      @close="closeResultModal"
      @saved="handleResultSaved"
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
