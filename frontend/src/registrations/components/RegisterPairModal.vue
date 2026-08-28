<script setup>
import { computed, ref, watch } from 'vue'

import PlayerFilters from '../../players/components/PlayerFilters.vue'
import PlayerService from '../../players/services/PlayerService'
import {
  isPlayerRegistrationRowSelectable,
  resolvePlayerRegistrationRowStatus,
} from '../../players/utils/playerRegistrationRowStatus'
import RegistrationService from '../services/RegistrationService'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  competitionId: {
    type: [String, Number],
    required: true,
  },
  competitionCategorySlug: {
    type: String,
    default: '',
  },
  registeredMemberIds: {
    type: Set,
    default: () => new Set(),
  },
})

const emit = defineEmits(['close', 'saved'])

const searchQuery = ref('')
const categoryId = ref('')
const clubId = ref('')
const players = ref([])
const player1Id = ref('')
const player2Id = ref('')
const isLoadingPlayers = ref(false)
const isSubmitting = ref(false)
const loadError = ref('')
const submitError = ref('')

const playerRowStatus = (player) =>
  resolvePlayerRegistrationRowStatus(player, {
    registeredPlayerIds: props.registeredMemberIds,
    competitionCategorySlug: props.competitionCategorySlug,
  })

const selectablePlayers = computed(() =>
  players.value.filter((player) => isPlayerRegistrationRowSelectable(playerRowStatus(player))),
)

const playerOptions = (excludePlayerId = null) =>
  selectablePlayers.value.filter((player) => player.id !== excludePlayerId)

const playerDisplayName = (player) => {
  const fullName = `${player.first_name ?? ''} ${player.last_name ?? ''}`.trim()

  if (player.nickname) {
    return `${fullName} (${player.nickname})`
  }

  return fullName || `Jugador #${player.id}`
}

const isConfirmDisabled = computed(
  () =>
    !player1Id.value ||
    !player2Id.value ||
    player1Id.value === player2Id.value ||
    isSubmitting.value,
)

const resetState = () => {
  searchQuery.value = ''
  categoryId.value = ''
  clubId.value = ''
  players.value = []
  player1Id.value = ''
  player2Id.value = ''
  loadError.value = ''
  submitError.value = ''
}

const loadPlayers = async () => {
  isLoadingPlayers.value = true
  loadError.value = ''

  try {
    players.value = await PlayerService.getPlayers({
      q: searchQuery.value.trim(),
      categoryId: categoryId.value,
      clubId: clubId.value,
    })
  } catch (error) {
    loadError.value = error?.response?.data?.message || 'No se pudo cargar la lista de jugadores.'
    players.value = []
  } finally {
    isLoadingPlayers.value = false
  }
}

const handleClose = () => {
  if (isSubmitting.value) {
    return
  }

  emit('close')
}

const handleSearch = async () => {
  submitError.value = ''
  await loadPlayers()
}

const handleConfirm = async () => {
  if (isConfirmDisabled.value) {
    return
  }

  isSubmitting.value = true
  submitError.value = ''

  try {
    const registration = await RegistrationService.registerPair(props.competitionId, [
      Number(player1Id.value),
      Number(player2Id.value),
    ])

    emit('saved', registration)
  } catch (error) {
    submitError.value =
      error?.response?.data?.errors?.player_ids?.[0] ||
      error?.response?.data?.message ||
      'No se pudo registrar la pareja.'
  } finally {
    isSubmitting.value = false
  }
}

watch(
  () => props.show,
  (isVisible) => {
    if (!isVisible) {
      resetState()
      return
    }

    resetState()
    loadPlayers()
  },
)

watch(player1Id, (value) => {
  if (value && value === player2Id.value) {
    player2Id.value = ''
  }
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
      @click.self="handleClose"
    >
      <div
        class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-md border border-slate-200 bg-white text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
        role="dialog"
        aria-modal="true"
        aria-labelledby="register-pair-modal-title"
      >
        <div class="space-y-4 overflow-y-auto p-4">
          <div>
            <h2
              id="register-pair-modal-title"
              class="text-lg font-semibold text-slate-900 dark:text-slate-100"
            >
              Registrar pareja
            </h2>
            <p class="mt-1 text-slate-600 dark:text-slate-300">
              Elegí dos jugadores distintos que aún no estén inscriptos en esta competencia.
            </p>
          </div>

          <PlayerFilters
            v-model:search-query="searchQuery"
            v-model:category-id="categoryId"
            v-model:club-id="clubId"
            compact
            :disabled="isLoadingPlayers || isSubmitting"
            @search="handleSearch"
          />

          <p v-if="loadError" class="text-red-600 dark:text-red-400">{{ loadError }}</p>
          <p v-else-if="isLoadingPlayers" class="text-slate-600 dark:text-slate-300">
            Cargando jugadores...
          </p>

          <template v-else>
            <div class="space-y-1">
              <label
                class="block text-sm font-medium text-slate-700 dark:text-slate-200"
                for="register-pair-player-1"
              >
                Jugador 1
              </label>
              <select
                id="register-pair-player-1"
                v-model="player1Id"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 dark:border-slate-600 dark:bg-slate-950"
                :disabled="isSubmitting"
              >
                <option value="" disabled>Seleccionar jugador</option>
                <option
                  v-for="player in playerOptions(player2Id ? Number(player2Id) : null)"
                  :key="`p1-${player.id}`"
                  :value="player.id"
                >
                  {{ playerDisplayName(player) }}
                </option>
              </select>
            </div>

            <div class="space-y-1">
              <label
                class="block text-sm font-medium text-slate-700 dark:text-slate-200"
                for="register-pair-player-2"
              >
                Jugador 2
              </label>
              <select
                id="register-pair-player-2"
                v-model="player2Id"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 dark:border-slate-600 dark:bg-slate-950"
                :disabled="isSubmitting"
              >
                <option value="" disabled>Seleccionar jugador</option>
                <option
                  v-for="player in playerOptions(player1Id ? Number(player1Id) : null)"
                  :key="`p2-${player.id}`"
                  :value="player.id"
                >
                  {{ playerDisplayName(player) }}
                </option>
              </select>
            </div>

            <p
              v-if="player1Id && player2Id && player1Id === player2Id"
              class="text-sm text-red-600 dark:text-red-400"
            >
              Los dos jugadores deben ser distintos.
            </p>

            <p
              v-else-if="selectablePlayers.length === 0"
              class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300"
            >
              No hay jugadores disponibles con esa búsqueda.
            </p>
          </template>

          <p v-if="submitError" class="text-red-600 dark:text-red-400">{{ submitError }}</p>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-200 p-4 dark:border-slate-700">
          <button
            type="button"
            class="rounded-md border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
            :disabled="isSubmitting"
            @click="handleClose"
          >
            Cancelar
          </button>
          <button
            type="button"
            class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
            :disabled="isConfirmDisabled"
            @click="handleConfirm"
          >
            {{ isSubmitting ? 'Registrando...' : 'Confirmar pareja' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
