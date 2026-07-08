<script setup>
import { ChevronDownIcon, UserGroupIcon } from '@heroicons/vue/24/outline'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import BracketService from '../../brackets/services/BracketService'
import CompetitionService from '../../competitions/services/CompetitionService'
import { structureLockReason } from '../../competitions/utils/competitionStructure'
import GameResultModal from '../../games/components/GameResultModal.vue'
import GameService from '../../games/services/GameService'
import StandingService from '../../standings/services/StandingService'
import GroupPlayerStatusModal from '../components/GroupPlayerStatusModal.vue'
import { getGroupPlayerStatusLabel } from '../constants/groupPlayerStatus'
import GroupService from '../services/GroupService'

const route = useRoute()

const groupId = computed(() => route.params.id)
const competitionId = computed(() => route.query.competitionId || '')
const groupName = computed(() => route.query.groupName || `Grupo #${groupId.value}`)
const competition = ref(null)
const hasBracket = ref(false)

const qualifiedPerGroup = computed(() => competition.value?.qualified_per_group ?? 2)

const competitionStructureLockReason = computed(() => structureLockReason(competition.value))

const groupPlayers = ref([])
const isLoadingGroupPlayers = ref(false)
const groupPlayersError = ref('')

const standings = ref([])
const isLoadingStandings = ref(false)

const isGeneratingRoundRobin = ref(false)
const roundRobinError = ref('')
const roundRobinSuccessMessage = ref('')

const selectedPlayerForStatus = ref(null)
const playerStatusSuccessMessage = ref('')

const resolveIsQualified = (standing, position) => {
  if (typeof standing?.eligible_for_qualification === 'boolean') {
    return standing.eligible_for_qualification
  }

  if (position !== null) {
    return position <= qualifiedPerGroup.value
  }

  return null
}

const isPlayerActive = (groupPlayer) => (groupPlayer?.status ?? 'active') === 'active'

const canChangePlayerStatus = (groupPlayer) =>
  isPlayerActive(groupPlayer) && !hasBracket.value && !hasGroupGames.value

const loadGroupPlayers = async () => {
  isLoadingGroupPlayers.value = true
  groupPlayersError.value = ''

  try {
    groupPlayers.value = await GroupService.listPlayers(groupId.value)
  } catch (error) {
    groupPlayersError.value =
      error?.response?.data?.message || 'No se pudo cargar los jugadores del grupo.'
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
      isQualified: null,
    }))
  }

  const displayed = standings.value
    .map((standing, index) => {
      const groupPlayer = groupPlayers.value.find(
        (currentGroupPlayer) => currentGroupPlayer.player?.id === standing.player_id,
      )

      if (!groupPlayer) {
        return null
      }

      const position = index + 1

      return {
        groupPlayer,
        position,
        isQualified: resolveIsQualified(standing, position),
      }
    })
    .filter(Boolean)

  const displayedPlayerIds = new Set(
    displayed.map((entry) => entry.groupPlayer.player?.id).filter(Boolean),
  )

  for (const groupPlayer of groupPlayers.value) {
    if (!displayedPlayerIds.has(groupPlayer.player?.id)) {
      displayed.push({
        groupPlayer,
        position: null,
        isQualified: null,
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

const playerCardClasses = (entry) => {
  if (!isPlayerActive(entry.groupPlayer)) {
    return 'border-slate-200 bg-slate-50/60 opacity-80 dark:border-slate-700 dark:bg-slate-800/40'
  }

  if (entry.isQualified === true) {
    return 'border-emerald-200 bg-emerald-50/40 dark:border-emerald-900 dark:bg-emerald-950/20'
  }

  return 'border-slate-200 dark:border-slate-700'
}

const groupPlayersAccordionSummaryClasses =
  'flex cursor-pointer list-none items-center gap-3 rounded-md p-4 text-sm transition hover:bg-slate-50 dark:hover:bg-slate-800/50 [&::-webkit-details-marker]:hidden'

const groupPlayersAccordionIconContainerClasses =
  'flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 ring-1 ring-slate-200 dark:bg-slate-800/80 dark:ring-slate-600'

const groupPlayersAccordionIconClasses =
  'h-5 w-5 text-slate-600 dark:text-slate-300'

const groupPlayersCount = computed(() => displayedGroupPlayers.value.length)

const groupPlayersCountLabel = computed(() => {
  const count = groupPlayersCount.value
  return `${count} jugador${count === 1 ? '' : 'es'}`
})

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
    const { standings: groupStandings } = await StandingService.listByGroup(groupId.value)
    standings.value = groupStandings
  } catch {
    standings.value = []
  } finally {
    isLoadingStandings.value = false
  }
}

const games = ref([])
const isLoadingGames = ref(false)
const gamesError = ref('')
const selectedGame = ref(null)
const resultSuccessMessage = ref('')
const resultError = ref('')

const hasGroupGames = computed(() => games.value.length > 0)

const groupPlayersTitle = computed(() =>
  hasGroupGames.value ? 'Jugadores del grupo' : 'Jugadores asignados',
)

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

const playerName = (player) => {
  if (!player?.id) {
    return 'Jugador no asignado'
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const isByeGame = (game) => game?.is_bye === true || !game?.player2?.id

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

const pendingLoadGames = computed(() =>
  games.value
    .filter(canLoadResult)
    .sort((left, right) => {
      const statusOrder = { in_progress: 0, pending: 1 }
      return (statusOrder[left.status] ?? 2) - (statusOrder[right.status] ?? 2) || left.id - right.id
    }),
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

const statusLabel = (game) => {
  if (isByeGame(game)) {
    return 'Avance automático'
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

const matchupLabel = (game) => `${playerName(game.player1)} vs ${playerName(game.player2)}`

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
  'space-y-2 rounded-md border border-amber-200 bg-amber-50/30 p-2.5 dark:border-amber-900/60 dark:bg-amber-950/20'

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
    await Promise.all([loadGroupGames(), loadStandings()])
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
    const createdGames = await GroupService.generateRoundRobin(groupId.value)
    roundRobinSuccessMessage.value = `Round robin generado. Partidos creados: ${createdGames.length}.`
    await Promise.all([loadGroupGames(), loadStandings()])
  } catch (error) {
    roundRobinError.value =
      error?.response?.data?.errors?.group?.[0] ||
      error?.response?.data?.message ||
      'No se pudo generar el round robin.'
  } finally {
    isGeneratingRoundRobin.value = false
  }
}

const openPlayerStatusModal = (groupPlayer) => {
  selectedPlayerForStatus.value = groupPlayer
  playerStatusSuccessMessage.value = ''
}

const closePlayerStatusModal = () => {
  selectedPlayerForStatus.value = null
}

const handlePlayerStatusSaved = async () => {
  closePlayerStatusModal()
  await Promise.all([loadGroupPlayers(), loadStandings(), loadGroupGames()])
  playerStatusSuccessMessage.value = 'Estado del jugador actualizado correctamente.'
}

onMounted(async () => {
  await Promise.all([
    loadGroupPlayers(),
    loadCompetition(),
    loadStandings(),
    loadGroupGames(),
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
          Cargando jugadores del grupo...
        </p>
        <p v-else-if="groupPlayersError" class="text-red-600 dark:text-red-400">{{ groupPlayersError }}</p>

        <div
          v-else-if="groupPlayers.length === 0"
          class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300"
        >
          Este grupo todavía no tiene jugadores asignados.
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
                {{ entry.groupPlayer.player.first_name }} {{ entry.groupPlayer.player.last_name }}
              </p>

              <span
                v-if="playerStatusLabel(entry.groupPlayer)"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="playerStatusBadgeClasses(entry.groupPlayer.status)"
              >
                {{ playerStatusLabel(entry.groupPlayer) }}
              </span>

              <span
                v-if="entry.isQualified === true"
                class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200"
              >
                <span aria-hidden="true">✓</span>
                Clasifica
              </span>

              <button
                v-if="canChangePlayerStatus(entry.groupPlayer)"
                type="button"
                class="ml-auto rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                @click="openPlayerStatusModal(entry.groupPlayer)"
              >
                Retirar / descalificar
              </button>
            </div>

            <p
              v-if="entry.groupPlayer.player.nickname"
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
        <p class="font-medium text-slate-700 dark:text-slate-200">Partidos del grupo</p>

        <button
          v-if="!hasGroupGames"
          type="button"
          class="shrink-0 rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-emerald-600 dark:hover:bg-emerald-500"
          :disabled="isGeneratingRoundRobin"
          @click="handleGenerateRoundRobin"
        >
          {{ isGeneratingRoundRobin ? 'Generando...' : 'Generar round robin' }}
        </button>
      </div>

      <p v-if="roundRobinError" class="text-red-600 dark:text-red-400">{{ roundRobinError }}</p>
      <p v-if="roundRobinSuccessMessage" class="text-emerald-700 dark:text-emerald-300">
        {{ roundRobinSuccessMessage }}
      </p>
      <p v-if="resultSuccessMessage" class="text-emerald-700 dark:text-emerald-300">
        {{ resultSuccessMessage }}
      </p>
      <p v-if="resultError" class="text-red-600 dark:text-red-400">{{ resultError }}</p>

      <p v-if="isLoadingGames" class="text-slate-600 dark:text-slate-300">Cargando partidos...</p>
      <p v-else-if="gamesError" class="text-red-600 dark:text-red-400">{{ gamesError }}</p>

      <div
        v-else-if="!hasGroupGames"
        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300"
      >
        Todavía no hay partidos generados para este grupo.
      </div>

      <div v-else class="space-y-4">
        <section v-if="pendingLoadGames.length > 0" class="space-y-2">
          <h2 class="font-medium text-slate-800 dark:text-slate-200">
            Pendientes de carga ({{ pendingLoadGames.length }})
          </h2>

          <ul class="space-y-2">
            <li
              v-for="game in pendingLoadGames"
              :key="`pending-${game.id}`"
              :class="pendingGameCardClasses"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="min-w-0 flex-1 font-medium text-slate-900 dark:text-slate-100">
                  {{ matchupLabel(game) }}
                </p>

                <span
                  class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="statusBadgeClasses(game)"
                >
                  {{ statusLabel(game) }}
                </span>
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
                    {{ playerName(game.player1) }}
                  </span>
                  <span class="shrink-0 tabular-nums text-sm text-slate-700 dark:text-slate-300">
                    {{ participantSetsWonLabel(game, 1) }}
                  </span>
                </div>
                <div
                  class="flex items-center justify-between gap-2 border-t border-slate-200 px-2 py-1.5 dark:border-slate-700"
                >
                  <span class="truncate text-sm text-slate-900 dark:text-slate-100">
                    {{ playerName(game.player2) }}
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

              <button
                type="button"
                class="w-full rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-600"
                @click="openResultModal(game)"
              >
                Cargar resultado
              </button>
            </li>
          </ul>
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
                  {{ playerName(game.player1) }}
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
    </div>

    <GameResultModal
      :show="Boolean(selectedGame)"
      :game="selectedGame"
      @close="closeResultModal"
      @saved="handleResultSaved"
    />

    <GroupPlayerStatusModal
      :show="Boolean(selectedPlayerForStatus)"
      :group-id="groupId"
      :player="selectedPlayerForStatus?.player ?? null"
      @close="closePlayerStatusModal"
      @saved="handlePlayerStatusSaved"
    />
  </section>
</template>
