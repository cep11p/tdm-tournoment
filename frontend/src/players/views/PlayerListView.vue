<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { usePermissions } from '../../composables/usePermissions'
import PlayerFilters from '../components/PlayerFilters.vue'
import PlayerService from '../services/PlayerService'

const { can } = usePermissions()
const canManagePlayers = computed(() => can('players.manage'))

const players = ref([])
const meta = ref({})
const searchQuery = ref('')
const categoryId = ref('')
const clubId = ref('')
const includeInactive = ref(false)
const currentPage = ref(1)
const perPage = 15

const isLoading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const actionError = ref('')
const actionLoadingId = ref(null)

const isEmpty = computed(() => !isLoading.value && players.value.length === 0)
const canGoPrevious = computed(() => (meta.value.current_page ?? 1) > 1)
const canGoNext = computed(
  () => (meta.value.current_page ?? 1) < (meta.value.last_page ?? 1),
)
const currentPageLabel = computed(() => meta.value.current_page ?? 1)
const lastPageLabel = computed(() => meta.value.last_page ?? 1)

const displayNickname = (player) => player.nickname || '-'
const displayCategory = (player) => player.category?.name || 'Sin categoría'
const displayClub = (player) => player.club?.name || 'Sin club'

const toggleActiveLabel = (player) => (player.active ? 'Desactivar' : 'Reactivar')

const loadPlayers = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const result = await PlayerService.listPaginated({
      page: currentPage.value,
      per_page: perPage,
      q: searchQuery.value,
      categoryId: categoryId.value,
      clubId: clubId.value,
      includeInactive: includeInactive.value,
    })

    players.value = result.data
    meta.value = result.meta
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'No se pudo cargar el listado de jugadores.'
    players.value = []
    meta.value = {}
  } finally {
    isLoading.value = false
  }
}

const handleSearch = async () => {
  currentPage.value = 1
  successMessage.value = ''
  actionError.value = ''
  await loadPlayers()
}

const goToPreviousPage = async () => {
  if (!canGoPrevious.value) {
    return
  }

  currentPage.value -= 1
  await loadPlayers()
}

const goToNextPage = async () => {
  if (!canGoNext.value) {
    return
  }

  currentPage.value += 1
  await loadPlayers()
}

const handleToggleActive = async (player) => {
  actionLoadingId.value = player.id
  actionError.value = ''
  successMessage.value = ''

  try {
    await PlayerService.update(player.id, { active: !player.active })
    successMessage.value = player.active
      ? 'Jugador desactivado correctamente.'
      : 'Jugador reactivado correctamente.'
    await loadPlayers()
  } catch (error) {
    actionError.value =
      error?.response?.data?.errors?.active?.[0] ||
      error?.response?.data?.message ||
      'No se pudo actualizar el estado del jugador.'
  } finally {
    actionLoadingId.value = null
  }
}

const handleDelete = async (player) => {
  const confirmed = window.confirm(
    `¿Eliminar a ${player.full_name || 'este jugador'}? Esta acción no se puede deshacer.`,
  )

  if (!confirmed) {
    return
  }

  actionLoadingId.value = player.id
  actionError.value = ''
  successMessage.value = ''

  try {
    await PlayerService.delete(player.id)
    successMessage.value = 'Jugador eliminado correctamente.'
    await loadPlayers()
  } catch (error) {
    actionError.value =
      error?.response?.data?.errors?.player?.[0] ||
      error?.response?.data?.message ||
      'No se pudo eliminar el jugador.'
  } finally {
    actionLoadingId.value = null
  }
}

watch(includeInactive, async () => {
  currentPage.value = 1
  successMessage.value = ''
  actionError.value = ''
  await loadPlayers()
})

onMounted(loadPlayers)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Jugadores</h1>
      <RouterLink
        v-if="canManagePlayers"
        to="/players/create"
        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
      >
        Nuevo jugador
      </RouterLink>
    </div>

    <PlayerFilters
      v-model:search-query="searchQuery"
      v-model:category-id="categoryId"
      v-model:club-id="clubId"
      v-model:include-inactive="includeInactive"
      show-include-inactive
      :disabled="isLoading"
      @search="handleSearch"
    />

    <p v-if="successMessage" class="text-sm text-emerald-700 dark:text-emerald-300">
      {{ successMessage }}
    </p>
    <p v-if="actionError" class="text-sm text-red-600 dark:text-red-400">{{ actionError }}</p>
    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando jugadores...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <div
      v-else-if="isEmpty"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      No se encontraron jugadores con los filtros actuales.
    </div>

    <div
      v-else
      class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
      <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
        <thead class="bg-slate-50 dark:bg-slate-800">
          <tr>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Nombre completo
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Apodo
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Categoría
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Club
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Estado
            </th>
            <th
              v-if="canManagePlayers"
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Acciones
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
          <tr
            v-for="player in players"
            :key="player.id"
            class="hover:bg-slate-50 dark:hover:bg-slate-800"
          >
            <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">
              {{ player.full_name }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ displayNickname(player) }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ displayCategory(player) }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ displayClub(player) }}
            </td>
            <td class="px-4 py-3 text-sm">
              <span
                v-if="player.active"
                class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
              >
                Activo
              </span>
              <span
                v-else
                class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300"
              >
                Inactivo
              </span>
            </td>
            <td v-if="canManagePlayers" class="px-4 py-3 text-sm">
              <div class="flex flex-wrap gap-2">
                <RouterLink
                  :to="`/players/${player.id}/edit`"
                  class="rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                  Editar
                </RouterLink>

                <button
                  type="button"
                  class="rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                  :disabled="actionLoadingId === player.id"
                  @click="handleToggleActive(player)"
                >
                  {{ toggleActiveLabel(player) }}
                </button>

                <button
                  type="button"
                  class="rounded-md border border-red-300 px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/30"
                  :disabled="actionLoadingId === player.id"
                  @click="handleDelete(player)"
                >
                  Eliminar
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div
      v-if="!isLoading && !errorMessage && !isEmpty"
      class="flex items-center justify-between gap-3"
    >
      <p class="text-sm text-slate-600 dark:text-slate-300">
        Página {{ currentPageLabel }} de {{ lastPageLabel }}
      </p>

      <div class="flex gap-2">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="!canGoPrevious || isLoading"
          @click="goToPreviousPage"
        >
          Anterior
        </button>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="!canGoNext || isLoading"
          @click="goToNextPage"
        >
          Siguiente
        </button>
      </div>
    </div>
  </section>
</template>
