<script setup>
import { computed, ref, watch } from 'vue'

import PlayerFilters from '../../players/components/PlayerFilters.vue'
import PlayerService from '../../players/services/PlayerService'
import {
  PLAYER_REGISTRATION_ROW_STATUS,
  resolvePlayerRegistrationRowStatus,
} from '../../players/utils/playerRegistrationRowStatus'
import RegistrationService from '../services/RegistrationService'
import {
  canSelectPlayer,
  emptyPairSlots,
  firstFreeSlot,
  isPairComplete,
  isPlayerAlreadySelected,
  removePlayerFromSlot,
  selectPlayer,
} from '../utils/pairSelection'

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
const selectedPlayers = ref(emptyPairSlots())
const hasSearched = ref(false)
const isLoadingPlayers = ref(false)
const isSubmitting = ref(false)
const loadError = ref('')
const submitError = ref('')

const bothSlotsOccupied = computed(() => firstFreeSlot(selectedPlayers.value) === -1)

const isConfirmDisabled = computed(
  () => !isPairComplete(selectedPlayers.value) || isSubmitting.value,
)

const playerRowStatus = (player) =>
  resolvePlayerRegistrationRowStatus(player, {
    registeredPlayerIds: props.registeredMemberIds,
    competitionCategorySlug: props.competitionCategorySlug,
  })

const playerDisplayName = (player) => {
  const fullName = `${player.first_name ?? ''} ${player.last_name ?? ''}`.trim()

  if (player.nickname) {
    return `${fullName} (${player.nickname})`
  }

  return fullName || `Jugador #${player.id}`
}

const displayCategory = (player) => player.category?.name || 'Sin categoría'
const displayClub = (player) => player.club?.name || 'Sin club'

const rowStatusLabel = (player) => {
  if (isPlayerAlreadySelected(player, selectedPlayers.value)) {
    return 'Ya elegido'
  }

  if (props.registeredMemberIds?.has?.(player.id)) {
    return 'Ya inscripto'
  }

  switch (playerRowStatus(player)) {
    case PLAYER_REGISTRATION_ROW_STATUS.UNAVAILABLE:
      return 'No disponible'
    case PLAYER_REGISTRATION_ROW_STATUS.CATEGORY_MISMATCH:
      return 'Categoría distinta'
    case PLAYER_REGISTRATION_ROW_STATUS.CATEGORY_UNINFORMED:
      return 'Sin categoría'
    default:
      return 'Disponible'
  }
}

const rowStatusClass = (player) => {
  if (isPlayerAlreadySelected(player, selectedPlayers.value)) {
    return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100'
  }

  switch (playerRowStatus(player)) {
    case PLAYER_REGISTRATION_ROW_STATUS.CATEGORY_MISMATCH:
      return 'bg-amber-100 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100'
    case PLAYER_REGISTRATION_ROW_STATUS.CATEGORY_UNINFORMED:
      return 'bg-sky-100 text-sky-900 dark:bg-sky-950/40 dark:text-sky-100'
    case PLAYER_REGISTRATION_ROW_STATUS.UNAVAILABLE:
      return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
    default:
      return 'text-slate-500 dark:text-slate-400'
  }
}

const canSelectRow = (player) =>
  canSelectPlayer(player, selectedPlayers.value, playerRowStatus(player))

const resetState = () => {
  searchQuery.value = ''
  categoryId.value = ''
  clubId.value = ''
  players.value = []
  selectedPlayers.value = emptyPairSlots()
  hasSearched.value = false
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
  hasSearched.value = true
  await loadPlayers()
}

const handleSelectPlayer = (player) => {
  if (isSubmitting.value) {
    return
  }

  submitError.value = ''
  selectedPlayers.value = selectPlayer(player, selectedPlayers.value, playerRowStatus(player))
}

const handleRemovePlayer = (index) => {
  if (isSubmitting.value) {
    return
  }

  submitError.value = ''
  selectedPlayers.value = removePlayerFromSlot(selectedPlayers.value, index)
}

const handleConfirm = async () => {
  if (isConfirmDisabled.value) {
    return
  }

  isSubmitting.value = true
  submitError.value = ''

  try {
    const registration = await RegistrationService.registerPair(props.competitionId, [
      selectedPlayers.value[0].id,
      selectedPlayers.value[1].id,
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
  },
)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
      @click.self="handleClose"
    >
      <div
        class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-md border border-slate-200 bg-white text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
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

          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <article
              v-for="(player, slotIndex) in selectedPlayers"
              :key="`pair-slot-${slotIndex}`"
              class="rounded-md border border-slate-200 p-3 dark:border-slate-700"
            >
              <div class="mb-2 flex items-center justify-between gap-2">
                <h3 class="font-medium text-slate-800 dark:text-slate-100">
                  Jugador {{ slotIndex + 1 }}
                </h3>
                <button
                  v-if="player"
                  type="button"
                  class="text-xs font-medium text-slate-600 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-70 dark:text-slate-300 dark:hover:text-slate-100"
                  :disabled="isSubmitting"
                  @click="handleRemovePlayer(slotIndex)"
                >
                  Quitar
                </button>
              </div>

              <template v-if="player">
                <p class="font-medium text-slate-900 dark:text-slate-100">
                  {{ playerDisplayName(player) }}
                </p>
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                  {{ displayCategory(player) }} · {{ displayClub(player) }}
                </p>
              </template>
              <p v-else class="text-slate-500 dark:text-slate-400">Sin seleccionar</p>
            </article>
          </div>

          <p
            v-if="bothSlotsOccupied"
            class="text-sm text-slate-600 dark:text-slate-300"
          >
            Quitá un jugador para reemplazarlo.
          </p>

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
          <p
            v-else-if="!hasSearched"
            class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300"
          >
            Usá los filtros para buscar jugadores.
          </p>
          <p
            v-else-if="players.length === 0"
            class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300"
          >
            No hay jugadores disponibles con esa búsqueda.
          </p>
          <ul
            v-else
            class="divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200 dark:divide-slate-700 dark:border-slate-700"
          >
            <li
              v-for="player in players"
              :key="player.id"
              class="flex items-center gap-3 px-3 py-2"
              :class="
                playerRowStatus(player) === PLAYER_REGISTRATION_ROW_STATUS.UNAVAILABLE
                  ? 'bg-slate-50 dark:bg-slate-800/40'
                  : ''
              "
            >
              <div class="min-w-0 flex-1">
                <p class="font-medium text-slate-900 dark:text-slate-100">
                  {{ playerDisplayName(player) }}
                </p>
                <p class="text-xs text-slate-600 dark:text-slate-400">
                  {{ displayCategory(player) }} · {{ displayClub(player) }}
                </p>
              </div>
              <span
                class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                :class="rowStatusClass(player)"
              >
                {{ rowStatusLabel(player) }}
              </span>
              <button
                v-if="canSelectRow(player)"
                type="button"
                class="shrink-0 rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                :disabled="isSubmitting"
                @click="handleSelectPlayer(player)"
              >
                Seleccionar
              </button>
            </li>
          </ul>

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
