<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import CompetitionService from '../../competitions/services/CompetitionService'
import { competitionHasGroupStage } from '../../competitions/constants/competitionFormats'
import {
  buildGroupPhaseAlert,
  summarizeGroupPhaseBracketGate,
} from '../../competitions/utils/buildGroupPhaseAlert'
import GameService from '../../games/services/GameService'
import GroupService from '../../groups/services/GroupService'
import GameResultModal from '../../games/components/GameResultModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import StandingService from '../../standings/services/StandingService'
import BracketService from '../services/BracketService'
import {
  BYE_BADGE_LABEL,
  BYE_BADGE_LABEL_GROUP_FIRST,
  PLAY_IN_BADGE_LABEL,
  PLAY_IN_ROUND_LABEL,
  QUALIFYING_ROUND_BANNER,
} from '../constants/bracketLabels'

const route = useRoute()
const competitionId = computed(() => route.params.id)
const competition = ref(null)
const groups = ref(null)
const games = ref(null)
const groupStandingsByGroupId = ref({})
const groupStandingsMetaByGroupId = ref({})

const bracket = ref(null)
const isLoading = ref(false)
const loadError = ref('')

const isCreatingBracket = ref(false)
const createError = ref('')
const createSuccessMessage = ref('')

const isGeneratingNextRound = ref(false)
const nextRoundError = ref('')
const nextRoundSuccessMessage = ref('')

const selectedGame = ref(null)
const resultSuccessMessage = ref('')

const hasBracket = computed(() => Boolean(bracket.value?.id))

const hasGroupStage = computed(() => competitionHasGroupStage(competition.value))

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

const bracketCreationBlockMessage = computed(() => {
  const code = statusSummary.value?.code

  if (!hasGroupStage.value) {
    if (code === 'awaiting_registrations') {
      return 'Se necesitan al menos 2 jugadores inscriptos para generar la llave.'
    }

    return null
  }

  if (code === 'group_stage_in_progress') {
    return 'Hay partidos de grupo pendientes. Completalos antes de generar la llave.'
  }

  if (code === 'group_stage_pending') {
    return 'Hay grupos sin partidos generados.'
  }

  if (code === 'group_stage_attention_required') {
    return (
      groupPhaseBracketBlockMessage.value ??
      statusSummary.value?.description ??
      'La fase de grupos requiere atención antes de generar la llave.'
    )
  }

  if (code === 'ready_for_bracket' && !allGroupsReadyForBracket.value) {
    return (
      groupPhaseBracketBlockMessage.value ??
      'La fase de grupos requiere atención antes de generar la llave.'
    )
  }

  if (code !== 'ready_for_bracket') {
    return statusSummary.value?.description ?? 'La fase de grupos todavía no está lista.'
  }

  return null
})

const canCreateBracket = computed(() => {
  if (hasBracket.value || !competition.value) {
    return false
  }

  if (!hasGroupStage.value) {
    return statusSummary.value?.code === 'ready_for_bracket'
  }

  return statusSummary.value?.code === 'ready_for_bracket' && allGroupsReadyForBracket.value
})

const showQualifiersPerGroup = computed(
  () => hasGroupStage.value && (bracket.value?.qualifiers_per_group ?? 0) > 0,
)

const initialRoundLabel = computed(() => {
  const games = bracket.value?.games
  if (!games?.length) {
    return null
  }

  const firstRound = Math.min(...games.map((game) => game.bracket_round || 1))
  const firstGame = games.find((game) => (game.bracket_round || 1) === firstRound)

  return firstGame?.round ?? null
})

const refreshBracket = async () => {
  bracket.value = await BracketService.show(competitionId.value)
}

const loadData = async () => {
  isLoading.value = true
  loadError.value = ''

  try {
    const [competitionData, bracketData, groupsData, gamesData] = await Promise.all([
      CompetitionService.show(competitionId.value),
      BracketService.show(competitionId.value),
      GroupService.listByCompetition(competitionId.value).catch(() => null),
      GameService.listByCompetition(competitionId.value).catch(() => null),
    ])

    competition.value = competitionData
    bracket.value = bracketData
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
    loadError.value = extractApiErrorMessage(error, 'No se pudo cargar la llave eliminatoria.')
  } finally {
    isLoading.value = false
  }
}

const playerName = (player) => {
  if (!player?.id) {
    return 'BYE'
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const isByeGame = (game) => game?.is_bye === true || !game?.player2?.id

const isQualifyingRoundLabel = (roundLabel) => roundLabel === PLAY_IN_ROUND_LABEL

const isQualifyingRoundGame = (game) => game?.round === PLAY_IN_ROUND_LABEL

const isPlayInGame = (game) => isQualifyingRoundGame(game) && !isByeGame(game)

const byeBadgeLabel = (game) => {
  if (!isByeGame(game)) {
    return null
  }

  if (isQualifyingRoundGame(game)) {
    return BYE_BADGE_LABEL_GROUP_FIRST
  }

  return BYE_BADGE_LABEL
}

const opponentLabel = (game, player) => {
  if (isByeGame(game)) {
    return isQualifyingRoundGame(game) ? 'Sin rival' : 'BYE'
  }

  return playerName(player)
}

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

const openResultModal = (game) => {
  selectedGame.value = game
  resultSuccessMessage.value = ''
}

const closeResultModal = () => {
  selectedGame.value = null
}

const handleResultSaved = async () => {
  closeResultModal()
  await refreshBracket()
  resultSuccessMessage.value = 'Resultado registrado correctamente.'
}

const statusLabel = (game) => {
  if (isByeGame(game)) {
    return isQualifyingRoundGame(game) ? BYE_BADGE_LABEL : 'Avance automático'
  }

  if (game?.status === 'finished') {
    return 'Finalizado'
  }

  if (game?.status === 'in_progress') {
    return 'En curso'
  }

  if (game?.status === 'pending') {
    return 'Pendiente'
  }

  return game?.status || 'Sin estado'
}

const winnerName = (game) => {
  if (isByeGame(game)) {
    return playerName(game.player1)
  }

  if (!game?.winner_id) {
    return '-'
  }

  if (game.winner_id === game.player1?.id) {
    return playerName(game.player1)
  }

  if (game.winner_id === game.player2?.id) {
    return playerName(game.player2)
  }

  return `Jugador #${game.winner_id}`
}

const statusBadgeClasses = (game) => {
  if (isByeGame(game) || game?.status === 'finished') {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
  }

  if (game?.status === 'in_progress') {
    return 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200'
  }

  return 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'
}

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

const groupedRounds = computed(() => {
  if (!bracket.value?.games?.length) {
    return []
  }

  const orderedGames = [...bracket.value.games].sort((left, right) => {
    if ((left.bracket_round || 0) !== (right.bracket_round || 0)) {
      return (left.bracket_round || 0) - (right.bracket_round || 0)
    }

    return (left.bracket_match || 0) - (right.bracket_match || 0)
  })

  const roundsMap = new Map()

  for (const game of orderedGames) {
    const roundNumber = game.bracket_round || 0
    const roundLabel = game.round || `Ronda ${roundNumber || '-'}`
    const key = `${roundNumber}-${roundLabel}`

    if (!roundsMap.has(key)) {
      roundsMap.set(key, {
        roundNumber,
        roundLabel,
        games: [],
      })
    }

    roundsMap.get(key).games.push(game)
  }

  return [...roundsMap.values()].sort((left, right) => left.roundNumber - right.roundNumber)
})

const hasQualifyingRound = computed(() =>
  groupedRounds.value.some((round) => isQualifyingRoundLabel(round.roundLabel)),
)

const MATCH_CARD_HEIGHT = 120
const MATCH_GAP = 20
const MATCH_SLOT_HEIGHT = MATCH_CARD_HEIGHT + MATCH_GAP

const roundSlotHeight = (roundIndex) => MATCH_SLOT_HEIGHT * Math.pow(2, roundIndex)
const pairConnectorHeight = (roundIndex) => roundSlotHeight(roundIndex)
const isEvenMatchIndex = (gameIndex) => gameIndex % 2 === 0
const hasNextRound = (roundIndex) => roundIndex < groupedRounds.value.length - 1

const isWinnerPlayer = (game, player) => {
  if (isByeGame(game) && player?.id === game.player1?.id) {
    return true
  }

  return Boolean(game?.winner_id && player?.id && game.winner_id === player.id)
}

const participantSetsWon = (game, playerNumber) => {
  if (isByeGame(game)) {
    return null
  }

  const key = playerNumber === 1 ? 'player1' : 'player2'
  const fromResource = game?.sets_won?.[key]

  if (typeof fromResource === 'number') {
    return fromResource
  }

  if (!Array.isArray(game?.sets) || game.sets.length === 0) {
    return null
  }

  let wins = 0

  for (const currentSet of game.sets) {
    if (playerNumber === 1 && currentSet.player1_score > currentSet.player2_score) {
      wins++
    } else if (playerNumber === 2 && currentSet.player2_score > currentSet.player1_score) {
      wins++
    }
  }

  return wins
}

const hasScoreData = (game) => {
  if (isByeGame(game)) {
    return false
  }

  if (typeof game?.sets_won?.player1 === 'number' && typeof game?.sets_won?.player2 === 'number') {
    return true
  }

  return Array.isArray(game?.sets) && game.sets.length > 0
}

const participantScoreLabel = (game, playerNumber) => {
  if (isByeGame(game)) {
    return playerNumber === 1 ? '✓' : '-'
  }

  if (!hasScoreData(game)) {
    return '-'
  }

  const sets = participantSetsWon(game, playerNumber)

  if (sets === null) {
    return '-'
  }

  return String(sets)
}

const compactPlayerRowClasses = (game, player) => {
  if (isByeGame(game) && !player?.id) {
    return 'italic text-slate-400 dark:text-slate-500'
  }

  if (isWinnerPlayer(game, player)) {
    return 'bg-emerald-50 font-semibold text-slate-900 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-900/30 dark:text-slate-100 dark:ring-emerald-700/50'
  }

  return 'text-slate-900 dark:text-slate-100'
}

const isFinalRound = (roundLabel) => roundLabel === 'Final'

const isQualifyingRound = (roundLabel) => isQualifyingRoundLabel(roundLabel)

const roundColumnClasses = () => 'w-[280px] shrink-0'

const roundHeaderClasses = (roundLabel) => {
  if (isQualifyingRound(roundLabel)) {
    return 'mb-1 text-center text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300'
  }

  if (!isFinalRound(roundLabel)) {
    return 'mb-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400'
  }

  return 'mb-3 text-center text-sm font-bold uppercase tracking-wide text-amber-800 dark:text-amber-300'
}

const roundHeaderHintClasses = () =>
  'mb-3 text-center text-xs text-indigo-600/90 dark:text-indigo-300/90'

const gameCardClasses = (round) => {
  if (isQualifyingRound(round.roundLabel)) {
    return 'border-indigo-200 bg-indigo-50/40 dark:border-indigo-800/60 dark:bg-indigo-950/20'
  }

  if (isFinalRound(round.roundLabel)) {
    return 'border-amber-500/70 bg-amber-50/60 dark:border-amber-600 dark:bg-amber-950/20'
  }

  return 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-950'
}

const finalGame = computed(() => {
  const games = bracket.value?.games

  if (!games?.length) {
    return null
  }

  return games.find((game) => game.round === 'Final') ?? null
})

const finalResult = computed(() => {
  const game = finalGame.value

  if (!game || game.status !== 'finished' || !game.winner_id) {
    return null
  }

  const champion = winnerName(game)
  let runnerUp = '-'

  if (game.winner_id === game.player1?.id) {
    runnerUp = playerName(game.player2)
  } else if (game.winner_id === game.player2?.id) {
    runnerUp = playerName(game.player1)
  }

  return { champion, runnerUp }
})

const canGenerateNextRound = computed(() => {
  if (!hasBracket.value || finalResult.value) {
    return false
  }

  const games = bracket.value?.games

  if (!games?.length) {
    return false
  }

  const currentRound = Math.max(...games.map((game) => game.bracket_round || 0))

  if (currentRound === 0) {
    return false
  }

  const currentRoundGames = games.filter((game) => (game.bracket_round || 0) === currentRound)

  if (currentRoundGames.length === 0) {
    return false
  }

  const currentRoundComplete = currentRoundGames.every(
    (game) => game.status === 'finished' && game.winner_id != null,
  )

  if (!currentRoundComplete) {
    return false
  }

  const nextRoundExists = games.some((game) => (game.bracket_round || 0) === currentRound + 1)

  return !nextRoundExists
})

const handleCreateBracket = async () => {
  isCreatingBracket.value = true
  createError.value = ''
  createSuccessMessage.value = ''
  nextRoundError.value = ''
  nextRoundSuccessMessage.value = ''

  try {
    bracket.value = await BracketService.create(competitionId.value, {})

    createSuccessMessage.value = 'Llave eliminatoria generada correctamente.'
  } catch (error) {
    createError.value = extractApiErrorMessage(
      error,
      'No se pudo generar la llave eliminatoria.',
    )
  } finally {
    isCreatingBracket.value = false
  }
}

const handleGenerateNextRound = async () => {
  if (!bracket.value?.id) {
    nextRoundError.value = 'Primero generá la llave eliminatoria.'
    return
  }

  isGeneratingNextRound.value = true
  nextRoundError.value = ''
  nextRoundSuccessMessage.value = ''
  createError.value = ''

  try {
    bracket.value = await BracketService.generateNextRound(bracket.value.id)
    nextRoundSuccessMessage.value = 'Siguiente ronda generada correctamente.'
  } catch (error) {
    nextRoundError.value = extractApiErrorMessage(
      error,
      'No se pudo generar la siguiente ronda.',
    )
  } finally {
    isGeneratingNextRound.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: competition?.tournament_id,
        competitionId,
        competitionName: competition?.name,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        Llave eliminatoria - {{ competition?.name || `Competencia #${competitionId}` }}
      </h1>
      <AppBackButton :fallback-to="`/competitions/${competitionId}`" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando llave eliminatoria...</p>
    <p v-else-if="loadError" class="text-sm text-red-600 dark:text-red-400">{{ loadError }}</p>

    <template v-else>
      <form
        v-if="!hasBracket && canCreateBracket"
        class="max-w-xl space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
        @submit.prevent="handleCreateBracket"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Generar llave eliminatoria</p>

        <p class="text-slate-600 dark:text-slate-300">
          Todavía no se generó la llave eliminatoria para esta competencia.
        </p>

        <p v-if="hasGroupStage" class="text-slate-600 dark:text-slate-300">
          Clasificados por grupo (configuración de la competencia):
          <span class="font-medium text-slate-900 dark:text-slate-100">{{ competition?.qualified_per_group ?? 2 }}</span>
        </p>

        <p v-else class="text-slate-600 dark:text-slate-300">
          La llave se genera directamente con los jugadores inscriptos.
        </p>

        <p v-if="createError" class="text-red-600 dark:text-red-400">{{ createError }}</p>
        <p v-if="createSuccessMessage" class="text-emerald-700 dark:text-emerald-300">{{ createSuccessMessage }}</p>

        <button
          type="submit"
          class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          :disabled="isCreatingBracket"
        >
          {{ isCreatingBracket ? 'Generando...' : 'Generar llave eliminatoria' }}
        </button>
      </form>

      <div
        v-else-if="!hasBracket"
        class="max-w-xl space-y-3 rounded-md border border-amber-200 bg-amber-50/60 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/20"
      >
        <p class="font-medium text-amber-900 dark:text-amber-100">Generar llave eliminatoria</p>

        <p class="text-amber-900/90 dark:text-amber-100/90">
          Todavía no se generó la llave eliminatoria para esta competencia.
        </p>

        <p class="rounded-md border border-amber-200 bg-white/70 px-3 py-2 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
          {{ bracketCreationBlockMessage ?? 'La fase de grupos todavía no está lista para generar la llave.' }}
        </p>

        <RouterLink
          :to="`/competitions/${competitionId}`"
          class="inline-flex text-sm font-medium text-amber-900 underline hover:text-amber-950 dark:text-amber-200 dark:hover:text-amber-100"
        >
          Volver al detalle de la competencia
        </RouterLink>
      </div>

      <div
        v-else
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Resumen de la llave</p>

        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Nombre</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.name }}</dd>
          </div>

          <div v-if="showQualifiersPerGroup">
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Clasifican por grupo</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.qualifiers_per_group }}</dd>
          </div>

          <div v-if="bracket.bracket_size">
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Tamaño</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.bracket_size }}</dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">BYEs</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.byes_count ?? 0 }}</dd>
          </div>

          <div v-if="initialRoundLabel">
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Ronda inicial</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ initialRoundLabel }}</dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Partidos</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.games?.length ?? 0 }}</dd>
          </div>
        </dl>
      </div>

      <div
        class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-medium text-slate-700 dark:text-slate-200">Rondas eliminatorias</p>

          <button
            v-if="canGenerateNextRound"
            type="button"
            class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
            :disabled="isGeneratingNextRound"
            @click="handleGenerateNextRound"
          >
            {{ isGeneratingNextRound ? 'Generando...' : 'Generar siguiente ronda' }}
          </button>
        </div>

        <p v-if="nextRoundError" class="text-red-600 dark:text-red-400">{{ nextRoundError }}</p>
        <p v-if="nextRoundSuccessMessage" class="text-emerald-700 dark:text-emerald-300">
          {{ nextRoundSuccessMessage }}
        </p>
        <p v-if="resultSuccessMessage" class="text-emerald-700 dark:text-emerald-300">
          {{ resultSuccessMessage }}
        </p>

        <div
          v-if="!hasBracket"
          class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-300"
        >
          Generá la llave eliminatoria para visualizar las rondas y partidos.
        </div>

        <div
          v-else-if="groupedRounds.length === 0"
          class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-300"
        >
          La llave no tiene partidos para mostrar.
        </div>

        <div v-else>
          <div
            v-if="finalResult"
            class="mb-4 rounded-md border border-amber-300/80 bg-amber-50/50 p-4 dark:border-amber-600/50 dark:bg-amber-950/30"
          >
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-200">
              Resultado final
            </p>
            <dl class="mt-2 space-y-1 text-sm">
              <div class="flex flex-wrap gap-x-2">
                <dt class="font-medium text-slate-700 dark:text-slate-300">Campeón:</dt>
                <dd class="font-semibold text-slate-900 dark:text-slate-100">{{ finalResult.champion }}</dd>
              </div>
              <div class="flex flex-wrap gap-x-2">
                <dt class="font-medium text-slate-700 dark:text-slate-300">Subcampeón:</dt>
                <dd class="text-slate-900 dark:text-slate-100">{{ finalResult.runnerUp }}</dd>
              </div>
            </dl>
          </div>

          <p class="mb-3 font-medium text-slate-700 dark:text-slate-200">Vista de llave</p>

          <div
            v-if="hasQualifyingRound"
            class="mb-4 rounded-md border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-800/60 dark:bg-indigo-950/30"
            role="note"
          >
            <p class="text-sm font-semibold text-indigo-900 dark:text-indigo-100">
              {{ QUALIFYING_ROUND_BANNER.title }}
            </p>
            <p class="mt-1 text-sm text-indigo-800/90 dark:text-indigo-200/90">
              {{ QUALIFYING_ROUND_BANNER.description }}
            </p>
          </div>

          <div class="overflow-x-auto pb-2">
            <div class="flex min-w-max items-start gap-8">
              <section
                v-for="(round, roundIndex) in groupedRounds"
                :key="`${round.roundNumber}-${round.roundLabel}`"
                :class="roundColumnClasses()"
              >
                <h2 :class="roundHeaderClasses(round.roundLabel)">
                  {{ round.roundLabel }}
                </h2>
                <p
                  v-if="isQualifyingRound(round.roundLabel)"
                  :class="roundHeaderHintClasses()"
                >
                  {{ QUALIFYING_ROUND_BANNER.roundHint }}
                </p>

                <ul class="space-y-0">
                  <li
                    v-for="(game, gameIndex) in round.games"
                    :key="game.id"
                    class="relative"
                    :style="{ height: `${roundSlotHeight(roundIndex)}px` }"
                  >
                    <article
                      class="absolute inset-x-0 top-1/2 -translate-y-1/2 rounded-md border p-2"
                      :class="gameCardClasses(round)"
                      :style="{ minHeight: `${MATCH_CARD_HEIGHT}px` }"
                    >
                      <div class="mb-1.5 flex flex-wrap gap-1">
                        <span
                          v-if="byeBadgeLabel(game)"
                          class="inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800 dark:bg-violet-900/60 dark:text-violet-200"
                        >
                          {{ byeBadgeLabel(game) }}
                        </span>
                        <span
                          v-if="isPlayInGame(game)"
                          class="inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200"
                        >
                          {{ PLAY_IN_BADGE_LABEL }}
                        </span>
                      </div>

                      <div
                        class="overflow-hidden rounded border border-slate-200 dark:border-slate-700"
                      >
                        <div
                          class="flex items-center justify-between gap-2 px-2 py-1.5"
                          :class="compactPlayerRowClasses(game, game.player1)"
                        >
                          <span class="truncate text-sm">{{ playerName(game.player1) }}</span>
                          <span class="shrink-0 tabular-nums text-sm">{{ participantScoreLabel(game, 1) }}</span>
                        </div>
                        <div
                          class="flex items-center justify-between gap-2 border-t border-slate-200 px-2 py-1.5 dark:border-slate-700"
                          :class="compactPlayerRowClasses(game, game.player2)"
                        >
                          <span class="truncate text-sm">{{ opponentLabel(game, game.player2) }}</span>
                          <span class="shrink-0 tabular-nums text-sm">{{ participantScoreLabel(game, 2) }}</span>
                        </div>
                      </div>

                      <div v-if="canLoadResult(game)" class="mt-2">
                        <button
                          type="button"
                          class="w-full rounded-md bg-emerald-700 px-2 py-1.5 text-xs font-medium text-white hover:bg-emerald-600"
                          @click="openResultModal(game)"
                        >
                          Cargar resultado
                        </button>
                      </div>

                      <details class="mt-1.5">
                        <summary
                          class="cursor-pointer text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                        >
                          Detalle
                        </summary>
                        <div class="mt-1.5 space-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                          <p v-if="matchFormatLabel(game)">
                            Formato: {{ matchFormatLabel(game) }}
                          </p>
                          <p>Estado: {{ statusLabel(game) }}</p>
                          <p v-if="setScoresDetail(game).length > 0">
                            Sets: {{ setScoresDetail(game).join(', ') }}
                          </p>
                          <p v-if="isByeGame(game) || game?.status === 'finished'">
                            Ganador: {{ winnerName(game) }}
                          </p>
                          <div v-if="isFinishedGame(game)" class="pt-0.5">
                            <RouterLink
                              :to="{
                                path: `/games/${game.id}`,
                                query: {
                                  competitionId,
                                  competitionName: competition?.name,
                                  tournamentId: competition?.tournament_id,
                                },
                              }"
                              class="font-medium text-slate-700 hover:underline dark:text-slate-300"
                            >
                              Ver detalle
                            </RouterLink>
                          </div>
                        </div>
                      </details>
                    </article>

                    <span
                      v-if="hasNextRound(roundIndex)"
                      class="absolute -right-4 top-1/2 hidden h-px w-4 border-t border-slate-300 dark:border-slate-600 sm:block"
                      aria-hidden="true"
                    />

                    <span
                      v-if="hasNextRound(roundIndex) && isEvenMatchIndex(gameIndex) && round.games[gameIndex + 1]"
                      class="pointer-events-none absolute hidden w-4 sm:block"
                      :style="{
                        right: '-16px',
                        top: '50%',
                        height: `${pairConnectorHeight(roundIndex)}px`,
                      }"
                      aria-hidden="true"
                    >
                      <span class="absolute right-0 top-0 h-full border-l border-slate-300 dark:border-slate-600" />
                      <span
                        class="absolute right-0 top-1/2 h-px w-4 translate-x-full border-t border-slate-300 dark:border-slate-600"
                      />
                    </span>
                  </li>
                </ul>
              </section>
            </div>
          </div>

          <details class="mt-6">
            <summary
              class="cursor-pointer text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200"
            >
              Detalle por rondas
            </summary>

            <div class="mt-3 space-y-4">
              <section
                v-for="round in groupedRounds"
                :key="`detail-${round.roundNumber}-${round.roundLabel}`"
                class="space-y-2 rounded-md border border-slate-200 p-3 dark:border-slate-700 dark:bg-slate-950/40"
              >
                <h2 class="font-semibold text-slate-900 dark:text-slate-100">
                  {{ round.roundLabel }}
                </h2>
                <p
                  v-if="isQualifyingRound(round.roundLabel)"
                  class="text-xs text-indigo-700 dark:text-indigo-300"
                >
                  {{ QUALIFYING_ROUND_BANNER.roundHint }}
                </p>

                <ul class="space-y-2">
                  <li
                    v-for="game in round.games"
                    :key="`detail-${game.id}`"
                    class="space-y-1 rounded-md border border-slate-200 p-3 dark:border-slate-700 dark:bg-slate-950/30"
                  >
                    <p class="font-medium text-slate-900 dark:text-slate-100">
                      {{ playerName(game.player1) }} vs {{ opponentLabel(game, game.player2) }}
                    </p>

                    <p v-if="matchFormatLabel(game)" class="text-slate-600 dark:text-slate-300">
                      {{ matchFormatLabel(game) }}
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                      <span
                        v-if="byeBadgeLabel(game)"
                        class="inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800 dark:bg-violet-900/60 dark:text-violet-200"
                      >
                        {{ byeBadgeLabel(game) }}
                      </span>

                      <span
                        v-if="isPlayInGame(game)"
                        class="inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200"
                      >
                        {{ PLAY_IN_BADGE_LABEL }}
                      </span>

                      <span
                        v-if="!isByeGame(game)"
                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="statusBadgeClasses(game)"
                      >
                        {{ statusLabel(game) }}
                      </span>
                      <span
                        v-else
                        class="inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800 dark:bg-violet-900/60 dark:text-violet-200"
                      >
                        {{ statusLabel(game) }}
                      </span>

                      <span v-if="setsResult(game)" class="text-slate-600 dark:text-slate-300">
                        Sets: {{ setsResult(game) }}
                      </span>
                    </div>

                    <p v-if="setScoresDetail(game).length > 0" class="text-slate-600 dark:text-slate-300">
                      Detalle: {{ setScoresDetail(game).join(', ') }}
                    </p>

                    <p class="text-slate-600 dark:text-slate-300">Ganador: {{ winnerName(game) }}</p>

                    <div v-if="canLoadResult(game)" class="pt-1">
                      <button
                        type="button"
                        class="rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-600"
                        @click="openResultModal(game)"
                      >
                        Cargar resultado
                      </button>
                    </div>

                    <div v-else-if="isFinishedGame(game)" class="pt-1">
                      <RouterLink
                        :to="{
                          path: `/games/${game.id}`,
                          query: {
                            competitionId,
                            competitionName: competition?.name,
                            tournamentId: competition?.tournament_id,
                          },
                        }"
                        class="text-xs font-medium text-slate-700 hover:underline dark:text-slate-300"
                      >
                        Ver detalle
                      </RouterLink>
                    </div>
                  </li>
                </ul>
              </section>
            </div>
          </details>
        </div>
      </div>

      <RouterLink
        :to="`/competitions/${competitionId}`"
        class="inline-flex text-sm font-medium text-slate-700 hover:text-slate-900 dark:text-slate-300 dark:hover:text-slate-100"
      >
        ← Volver al detalle de la competencia
      </RouterLink>
    </template>

    <GameResultModal
      :show="Boolean(selectedGame)"
      :game="selectedGame"
      @close="closeResultModal"
      @saved="handleResultSaved"
    />
  </section>
</template>
