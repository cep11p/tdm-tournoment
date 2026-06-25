<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import BracketService from '../../brackets/services/BracketService'
import CompetitionService from '../../competitions/services/CompetitionService'
import GameResultModal from '../../games/components/GameResultModal.vue'
import GameService from '../../games/services/GameService'
import RegistrationService from '../../registrations/services/RegistrationService'
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

const groupPlayers = ref([])
const isLoadingGroupPlayers = ref(false)
const groupPlayersError = ref('')

const standings = ref([])
const isLoadingStandings = ref(false)

const registeredPlayers = ref([])
const isLoadingRegisteredPlayers = ref(false)
const registeredPlayersError = ref('')

const selectedPlayerId = ref('')
const isAssigningPlayer = ref(false)
const assignError = ref('')
const assignSuccessMessage = ref('')

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

const canChangePlayerStatus = (groupPlayer) => isPlayerActive(groupPlayer) && !hasBracket.value

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

const loadRegisteredPlayers = async () => {
  if (!competitionId.value) {
    registeredPlayers.value = []
    registeredPlayersError.value =
      'No se recibió competitionId en la ruta. Volvé al listado de grupos para asignar jugadores.'
    return
  }

  isLoadingRegisteredPlayers.value = true
  registeredPlayersError.value = ''

  try {
    const registrations = await RegistrationService.listByCompetition(competitionId.value)
    registeredPlayers.value = registrations
      .map((registration) => registration.player)
      .filter((player) => player?.id)
  } catch (error) {
    registeredPlayersError.value =
      error?.response?.data?.message || 'No se pudo cargar el listado de inscriptos.'
  } finally {
    isLoadingRegisteredPlayers.value = false
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

  if (entry.isQualified === false) {
    return 'border-red-200 bg-red-50/30 dark:border-red-900 dark:bg-red-950/10'
  }

  return 'border-slate-200 dark:border-slate-700'
}

const qualificationBadgeClasses = (isQualified) => {
  if (isQualified === true) {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
  }

  if (isQualified === false) {
    return 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
  }

  return ''
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

const availablePlayers = computed(() => {
  const assignedPlayerIds = new Set(
    groupPlayers.value.map((groupPlayer) => groupPlayer.player?.id).filter(Boolean),
  )

  return registeredPlayers.value.filter((player) => !assignedPlayerIds.has(player.id))
})

const handleAssignPlayer = async () => {
  if (!selectedPlayerId.value) {
    assignError.value = 'Seleccioná un jugador para asignar.'
    return
  }

  isAssigningPlayer.value = true
  assignError.value = ''
  assignSuccessMessage.value = ''
  roundRobinError.value = ''
  roundRobinSuccessMessage.value = ''

  try {
    await GroupService.assignPlayer(groupId.value, Number(selectedPlayerId.value))
    selectedPlayerId.value = ''
    assignSuccessMessage.value = 'Jugador asignado correctamente.'
    await Promise.all([loadGroupPlayers(), loadStandings()])
  } catch (error) {
    assignError.value =
      error?.response?.data?.errors?.player_id?.[0] ||
      error?.response?.data?.message ||
      'No se pudo asignar el jugador.'
  } finally {
    isAssigningPlayer.value = false
  }
}

const games = ref([])
const isLoadingGames = ref(false)
const gamesError = ref('')
const selectedGame = ref(null)
const resultSuccessMessage = ref('')

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

const groupGames = computed(() => games.value)

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

const openResultModal = (game) => {
  selectedGame.value = game
  resultSuccessMessage.value = ''
}

const closeResultModal = () => {
  selectedGame.value = null
}

const handleResultSaved = async () => {
  closeResultModal()
  await Promise.all([loadGroupGames(), loadStandings()])
  resultSuccessMessage.value = 'Resultado registrado correctamente.'
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
    loadRegisteredPlayers(),
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
        <AppBackButton :fallback-to="competitionId ? `/competitions/${competitionId}/groups` : '/competitions'" />

        <RouterLink
          :to="`/groups/${groupId}/standings?competitionId=${competitionId}&groupName=${encodeURIComponent(groupName)}`"
          class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-300"
        >
          Ver posiciones
        </RouterLink>
      </div>
    </div>

    <div
      class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
    >
      <p class="font-medium text-slate-700 dark:text-slate-200">Asignar jugador registrado</p>

      <p v-if="isLoadingRegisteredPlayers" class="text-slate-600 dark:text-slate-300">
        Cargando jugadores inscriptos...
      </p>
      <p v-else-if="registeredPlayersError" class="text-red-600 dark:text-red-400">
        {{ registeredPlayersError }}
      </p>

      <template v-else>
        <div v-if="availablePlayers.length === 0" class="text-slate-600 dark:text-slate-300">
          No hay jugadores inscriptos disponibles para asignar.
        </div>

        <form v-else class="flex items-end gap-2" @submit.prevent="handleAssignPlayer">
          <label class="flex-1">
            <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Jugador</span>
            <select
              v-model="selectedPlayerId"
              class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            >
              <option value="">Seleccionar jugador</option>
              <option v-for="player in availablePlayers" :key="player.id" :value="player.id">
                {{ player.first_name }} {{ player.last_name }}
                {{ player.nickname ? `(${player.nickname})` : '' }}
              </option>
            </select>
          </label>

          <button
            type="submit"
            class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
            :disabled="isAssigningPlayer"
          >
            {{ isAssigningPlayer ? 'Asignando...' : 'Asignar' }}
          </button>
        </form>

        <p v-if="assignError" class="text-red-600 dark:text-red-400">{{ assignError }}</p>
        <p v-if="assignSuccessMessage" class="text-emerald-700 dark:text-emerald-300">
          {{ assignSuccessMessage }}
        </p>
      </template>
    </div>

    <div
      class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
    >
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="font-medium text-slate-700 dark:text-slate-200">Jugadores asignados</p>
          <p
            v-if="!isLoadingGroupPlayers && !isLoadingStandings && standings.length > 0"
            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
          >
            Ordenados por posición actual · clasifican los primeros {{ qualifiedPerGroup }}
          </p>
        </div>

        <button
          type="button"
          class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-emerald-600 dark:hover:bg-emerald-500"
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

      <div v-else class="space-y-2">
        <article
          v-for="entry in displayedGroupPlayers"
          :key="entry.groupPlayer.id"
          class="rounded border p-3"
          :class="playerCardClasses(entry)"
        >
          <div class="flex flex-wrap items-center gap-2">
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
              v-if="entry.isQualified !== null"
              class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
              :class="qualificationBadgeClasses(entry.isQualified)"
            >
              <span aria-hidden="true">{{ entry.isQualified ? '✓' : '✗' }}</span>
              {{ entry.isQualified ? 'Clasifica' : 'Eliminado' }}
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

          <p class="mt-1 text-slate-600 dark:text-slate-300">
            Nickname: {{ entry.groupPlayer.player.nickname || '-' }}
          </p>
          <p
            v-if="entry.groupPlayer.status_notes"
            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
          >
            Notas: {{ entry.groupPlayer.status_notes }}
          </p>
        </article>
      </div>
    </div>

    <div
      class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
    >
      <p class="font-medium text-slate-700 dark:text-slate-200">Partidos del grupo</p>

      <p v-if="resultSuccessMessage" class="text-emerald-700 dark:text-emerald-300">
        {{ resultSuccessMessage }}
      </p>

      <p v-if="isLoadingGames" class="text-slate-600 dark:text-slate-300">Cargando partidos...</p>
      <p v-else-if="gamesError" class="text-red-600 dark:text-red-400">{{ gamesError }}</p>

      <div
        v-else-if="groupGames.length === 0"
        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300"
      >
        Todavía no hay partidos generados para este grupo.
      </div>

      <ul v-else class="space-y-2">
        <li
          v-for="game in groupGames"
          :key="game.id"
          class="space-y-1 rounded-md border border-slate-200 p-3 dark:border-slate-700 dark:bg-slate-950/30"
        >
          <p class="font-medium text-slate-900 dark:text-slate-100">
            {{ playerName(game.player1) }} vs {{ playerName(game.player2) }}
          </p>

          <p v-if="matchFormatLabel(game)" class="text-slate-600 dark:text-slate-300">
            {{ matchFormatLabel(game) }}
          </p>

          <div class="flex flex-wrap items-center gap-2">
            <span
              v-if="isByeGame(game)"
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

            <span v-if="setsResult(game)" class="text-slate-600 dark:text-slate-300">
              Resultado: {{ setsResult(game) }}
            </span>
          </div>

          <p v-if="setScoresDetail(game).length > 0" class="text-slate-600 dark:text-slate-300">
            Detalle: {{ setScoresDetail(game).join(', ') }}
          </p>

          <p v-if="isFinishedGame(game)" class="text-slate-600 dark:text-slate-300">
            Ganador: {{ winnerName(game) }}
          </p>

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
