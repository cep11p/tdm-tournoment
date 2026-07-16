<script setup>
import { computed, ref, watch } from 'vue'

import PlayerFilters from '../../players/components/PlayerFilters.vue'
import PlayerService from '../../players/services/PlayerService'
import {
  isPlayerRegistrationRowSelectable,
  isPlayerRegistrationRowWarning,
  PLAYER_REGISTRATION_ROW_STATUS,
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
  registeredPlayerIds: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['close', 'saved'])

const searchQuery = ref('')
const categoryId = ref('')
const clubId = ref('')
const players = ref([])
const selectedIds = ref(new Set())
const isLoadingPlayers = ref(false)
const isSubmitting = ref(false)
const loadError = ref('')
const submitError = ref('')
const successMessage = ref('')

const registeredSet = computed(() => new Set(props.registeredPlayerIds))

const visiblePlayers = computed(() => players.value)

const playerRowStatus = (player) =>
  resolvePlayerRegistrationRowStatus(player, {
    registeredPlayerIds: registeredSet.value,
    competitionCategorySlug: props.competitionCategorySlug,
  })

const selectablePlayers = computed(() =>
  visiblePlayers.value.filter((player) =>
    isPlayerRegistrationRowSelectable(playerRowStatus(player)),
  ),
)

const selectedCount = computed(() => selectedIds.value.size)

const selectedWarningCount = computed(() => {
  let count = 0

  for (const playerId of selectedIds.value) {
    const player = players.value.find((entry) => entry.id === playerId)

    if (player && isPlayerRegistrationRowWarning(playerRowStatus(player))) {
      count += 1
    }
  }

  return count
})

const allFilteredSelected = computed(() => {
  if (selectablePlayers.value.length === 0) {
    return false
  }

  return selectablePlayers.value.every((player) => selectedIds.value.has(player.id))
})

const someFilteredSelected = computed(() => {
  if (allFilteredSelected.value) {
    return false
  }

  return selectablePlayers.value.some((player) => selectedIds.value.has(player.id))
})

const selectAllCheckbox = ref(null)

const isConfirmDisabled = computed(() => selectedCount.value === 0 || isSubmitting.value)

const playerDisplayName = (player) => {
  const fullName = `${player.first_name ?? ''} ${player.last_name ?? ''}`.trim()

  if (player.nickname) {
    return `${fullName} (${player.nickname})`
  }

  return fullName || `Jugador #${player.id}`
}

const displayCategory = (player) => player.category?.name || 'Sin categoría'
const displayClub = (player) => player.club?.name || 'Sin club'

const rowStatusLabel = (status) => {
  switch (status) {
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

const rowStatusClass = (status) => {
  switch (status) {
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

const resetState = () => {
  searchQuery.value = ''
  categoryId.value = ''
  clubId.value = ''
  players.value = []
  selectedIds.value = new Set()
  loadError.value = ''
  submitError.value = ''
  successMessage.value = ''
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

const isPlayerSelected = (playerId) => selectedIds.value.has(playerId)

const togglePlayer = (player) => {
  const status = playerRowStatus(player)

  if (!isPlayerRegistrationRowSelectable(status)) {
    return
  }

  const next = new Set(selectedIds.value)

  if (next.has(player.id)) {
    next.delete(player.id)
  } else {
    next.add(player.id)
  }

  selectedIds.value = next
}

const selectAllVisible = () => {
  const next = new Set(selectedIds.value)

  for (const player of selectablePlayers.value) {
    next.add(player.id)
  }

  selectedIds.value = next
}

const toggleSelectAllFiltered = () => {
  if (allFilteredSelected.value) {
    const next = new Set(selectedIds.value)

    for (const player of selectablePlayers.value) {
      next.delete(player.id)
    }

    selectedIds.value = next
    return
  }

  selectAllVisible()
}

const clearSelection = () => {
  selectedIds.value = new Set()
}

const handleClose = () => {
  if (isSubmitting.value) {
    return
  }

  emit('close')
}

const handleSearch = async () => {
  submitError.value = ''
  successMessage.value = ''
  await loadPlayers()
}

const handleConfirm = async () => {
  if (isConfirmDisabled.value) {
    return
  }

  isSubmitting.value = true
  submitError.value = ''
  successMessage.value = ''

  try {
    const result = await RegistrationService.bulkRegister(
      props.competitionId,
      [...selectedIds.value],
    )

    successMessage.value =
      result?.message ||
      `Inscripción masiva procesada: ${result?.created ?? 0} inscriptos, ${result?.skipped ?? 0} omitidos.`

    emit('saved', result)
  } catch (error) {
    submitError.value =
      error?.response?.data?.errors?.player_ids?.[0] ||
      error?.response?.data?.message ||
      'No se pudo completar la inscripción masiva.'
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

watch([allFilteredSelected, someFilteredSelected, selectablePlayers], () => {
  if (!selectAllCheckbox.value) {
    return
  }

  selectAllCheckbox.value.indeterminate = someFilteredSelected.value
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
        class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-md border border-slate-200 bg-white text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
        role="dialog"
        aria-modal="true"
        aria-labelledby="bulk-registration-modal-title"
      >
        <div class="space-y-4 overflow-y-auto p-4">
          <div>
            <h2
              id="bulk-registration-modal-title"
              class="text-lg font-semibold text-slate-900 dark:text-slate-100"
            >
              Inscribir jugadores
            </h2>
            <p class="mt-1 text-slate-600 dark:text-slate-300">
              Buscá jugadores existentes y seleccioná varios para inscribirlos en la competencia.
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

          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
              :disabled="selectablePlayers.length === 0 || isSubmitting"
              @click="toggleSelectAllFiltered"
            >
              {{
                allFilteredSelected
                  ? 'Deseleccionar filtrados'
                  : `Seleccionar todos los filtrados (${selectablePlayers.length})`
              }}
            </button>

            <button
              type="button"
              class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
              :disabled="selectedCount === 0 || isSubmitting"
              @click="clearSelection"
            >
              Limpiar selección
            </button>

            <span v-if="selectedCount > 0" class="text-xs text-slate-600 dark:text-slate-400">
              {{ selectedCount }} seleccionado{{ selectedCount === 1 ? '' : 's' }}
              <template v-if="selectedWarningCount > 0">
                · {{ selectedWarningCount }} con advertencia
              </template>
            </span>
          </div>

          <p v-if="loadError" class="text-red-600 dark:text-red-400">{{ loadError }}</p>

          <p v-else-if="isLoadingPlayers" class="text-slate-600 dark:text-slate-300">
            Cargando jugadores...
          </p>

          <div
            v-else-if="visiblePlayers.length === 0"
            class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300"
          >
            No se encontraron jugadores con esa búsqueda.
          </div>

          <div
            v-else
            class="overflow-hidden rounded-md border border-slate-200 dark:border-slate-700"
          >
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
              <thead class="bg-slate-50 dark:bg-slate-800/60">
                <tr>
                  <th scope="col" class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    <label class="inline-flex items-center gap-2 normal-case">
                      <input
                        ref="selectAllCheckbox"
                        type="checkbox"
                        :checked="allFilteredSelected"
                        :disabled="selectablePlayers.length === 0 || isSubmitting"
                        @change="toggleSelectAllFiltered"
                      />
                      <span>Todos</span>
                    </label>
                  </th>
                  <th scope="col" class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Jugador
                  </th>
                  <th scope="col" class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Categoría
                  </th>
                  <th scope="col" class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Club
                  </th>
                  <th scope="col" class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Estado
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
                <tr
                  v-for="player in visiblePlayers"
                  :key="player.id"
                  class="text-slate-900 dark:text-slate-100"
                  :class="playerRowStatus(player) === PLAYER_REGISTRATION_ROW_STATUS.UNAVAILABLE ? 'bg-slate-50 dark:bg-slate-800/40' : ''"
                >
                  <td class="px-3 py-2">
                    <input
                      type="checkbox"
                      :checked="isPlayerSelected(player.id)"
                      :disabled="!isPlayerRegistrationRowSelectable(playerRowStatus(player)) || isSubmitting"
                      @change="togglePlayer(player)"
                    />
                  </td>
                  <td class="px-3 py-2">
                    {{ playerDisplayName(player) }}
                  </td>
                  <td class="px-3 py-2 text-xs text-slate-600 dark:text-slate-300">
                    {{ displayCategory(player) }}
                  </td>
                  <td class="px-3 py-2 text-xs text-slate-600 dark:text-slate-300">
                    {{ displayClub(player) }}
                  </td>
                  <td class="px-3 py-2">
                    <span
                      v-if="registeredSet.has(player.id)"
                      class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                    >
                      Ya inscripto
                    </span>
                    <span
                      v-else-if="playerRowStatus(player) !== PLAYER_REGISTRATION_ROW_STATUS.COMPATIBLE"
                      class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                      :class="rowStatusClass(playerRowStatus(player))"
                    >
                      {{ rowStatusLabel(playerRowStatus(player)) }}
                    </span>
                    <span v-else class="text-xs text-slate-500 dark:text-slate-400">Disponible</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-if="submitError" class="text-red-600 dark:text-red-400">{{ submitError }}</p>
          <p v-if="successMessage" class="text-emerald-700 dark:text-emerald-300">{{ successMessage }}</p>
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
            {{ isSubmitting ? 'Inscribiendo...' : 'Confirmar inscripción' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
