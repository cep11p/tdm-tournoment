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
  teamSize: {
    type: Number,
    default: 4,
  },
  registeredMemberIds: {
    type: Set,
    default: () => new Set(),
  },
})

const emit = defineEmits(['close', 'saved'])

const teamName = ref('')
const searchQuery = ref('')
const categoryId = ref('')
const clubId = ref('')
const players = ref([])
const selectedPlayerIds = ref([])
const isLoadingPlayers = ref(false)
const isSubmitting = ref(false)
const loadError = ref('')
const submitError = ref('')

const normalizedTeamSize = computed(() => Math.max(2, Number(props.teamSize) || 4))

const playerRowStatus = (player) =>
  resolvePlayerRegistrationRowStatus(player, {
    registeredPlayerIds: props.registeredMemberIds,
    competitionCategorySlug: props.competitionCategorySlug,
  })

const selectablePlayers = computed(() =>
  players.value.filter((player) => isPlayerRegistrationRowSelectable(playerRowStatus(player))),
)

const playerOptionsForSlot = (slotIndex) => {
  const otherSelected = selectedPlayerIds.value.filter((_, index) => index !== slotIndex)

  return selectablePlayers.value.filter((player) => !otherSelected.includes(player.id))
}

const playerDisplayName = (player) => {
  const fullName = `${player.first_name ?? ''} ${player.last_name ?? ''}`.trim()

  if (player.nickname) {
    return `${fullName} (${player.nickname})`
  }

  return fullName || `Jugador #${player.id}`
}

const isConfirmDisabled = computed(() => {
  if (isSubmitting.value) {
    return true
  }

  if (!teamName.value.trim()) {
    return true
  }

  return selectedPlayerIds.value.some((id) => !id)
})

const resetState = () => {
  teamName.value = ''
  searchQuery.value = ''
  categoryId.value = ''
  clubId.value = ''
  players.value = []
  selectedPlayerIds.value = Array.from({ length: normalizedTeamSize.value }, () => '')
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
    const registration = await RegistrationService.registerTeam(
      props.competitionId,
      teamName.value.trim(),
      selectedPlayerIds.value.map((id) => Number(id)),
    )

    emit('saved', registration)
  } catch (error) {
    submitError.value =
      error?.response?.data?.errors?.name?.[0] ||
      error?.response?.data?.errors?.player_ids?.[0] ||
      error?.response?.data?.message ||
      'No se pudo registrar el equipo.'
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

watch(
  () => props.teamSize,
  () => {
    if (props.show) {
      selectedPlayerIds.value = Array.from({ length: normalizedTeamSize.value }, () => '')
    }
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
        class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-md border border-slate-200 bg-white text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
        role="dialog"
        aria-modal="true"
        aria-labelledby="register-team-modal-title"
      >
        <div class="space-y-4 overflow-y-auto p-4">
          <div>
            <h2
              id="register-team-modal-title"
              class="text-lg font-semibold text-slate-900 dark:text-slate-100"
            >
              Registrar equipo
            </h2>
            <p class="mt-1 text-slate-600 dark:text-slate-300">
              Elegí un nombre y {{ normalizedTeamSize }} integrantes distintos que aún no estén
              inscriptos en esta competencia.
            </p>
          </div>

          <div class="space-y-1">
            <label
              class="block text-sm font-medium text-slate-700 dark:text-slate-200"
              for="register-team-name"
            >
              Nombre del equipo
            </label>
            <input
              id="register-team-name"
              v-model="teamName"
              type="text"
              maxlength="255"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 dark:border-slate-600 dark:bg-slate-950"
              :disabled="isSubmitting"
              placeholder="Ej: Club Andino A"
            />
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
            <div
              v-for="(_, slotIndex) in normalizedTeamSize"
              :key="`team-player-${slotIndex}`"
              class="space-y-1"
            >
              <label
                class="block text-sm font-medium text-slate-700 dark:text-slate-200"
                :for="`register-team-player-${slotIndex}`"
              >
                Jugador {{ slotIndex + 1 }}
              </label>
              <select
                :id="`register-team-player-${slotIndex}`"
                v-model="selectedPlayerIds[slotIndex]"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 dark:border-slate-600 dark:bg-slate-950"
                :disabled="isSubmitting"
              >
                <option value="" disabled>Seleccionar jugador</option>
                <option
                  v-for="player in playerOptionsForSlot(slotIndex)"
                  :key="`slot-${slotIndex}-player-${player.id}`"
                  :value="player.id"
                >
                  {{ playerDisplayName(player) }}
                </option>
              </select>
            </div>

            <p
              v-if="selectablePlayers.length === 0"
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
            {{ isSubmitting ? 'Registrando...' : 'Confirmar equipo' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
