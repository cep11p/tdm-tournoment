<script setup>
import {
  CheckCircleIcon,
  EyeIcon,
  NoSymbolIcon,
  PencilSquareIcon,
  PlusIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'

import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import AppTooltip from '../../components/AppTooltip.vue'
import { usePermissions } from '../../composables/usePermissions'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import RankingFormModal from '../components/RankingFormModal.vue'
import RankingService from '../services/RankingService'
import {
  RANKING_DEACTIVATE_HISTORY_NOTE,
  rankingCategoryLabel,
  rankingStatusBadgeClasses,
  rankingStatusLabel,
  rankingTypeLabel,
} from '../utils/rankingDisplay'

const router = useRouter()
const { can } = usePermissions()
const canManageRankings = computed(() => can('rankings.manage'))

const rankings = ref([])
const isLoading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const actionError = ref('')
const actionLoadingId = ref(null)
const showCreateModal = ref(false)
const showEditModal = ref(false)
const editingRanking = ref(null)

const viewButtonClasses =
  'inline-flex rounded-md border border-blue-300 bg-blue-50 p-1.5 text-blue-700 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-950/60'

const addButtonClasses =
  'inline-flex rounded-md border border-emerald-300 bg-emerald-50 p-2 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60'

const editButtonClasses =
  'inline-flex rounded-md border border-amber-300 bg-amber-50 p-1.5 text-amber-800 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60'

const activateButtonClasses =
  'inline-flex rounded-md border border-emerald-300 bg-emerald-50 p-1.5 text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60'

const deactivateButtonClasses =
  'inline-flex rounded-md border border-slate-300 bg-slate-50 p-1.5 text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'

const deleteButtonClasses =
  'inline-flex rounded-md border border-red-300 bg-red-50 p-1.5 text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300 dark:hover:bg-red-950/50'

const retryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const hasHistory = (ranking) => ranking?.has_history === true

const loadRankings = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    rankings.value = await RankingService.index()
  } catch {
    errorMessage.value = 'No se pudo cargar el ranking.'
    rankings.value = []
  } finally {
    isLoading.value = false
  }
}

const openCreateModal = () => {
  successMessage.value = ''
  actionError.value = ''
  showCreateModal.value = true
}

const handleCreateClose = () => {
  showCreateModal.value = false
}

const handleCreateSaved = async (ranking, meta) => {
  showCreateModal.value = false
  successMessage.value = 'Ranking creado correctamente.'

  if (meta?.copied) {
    await router.push({ name: 'rankings.show', params: { id: ranking.id } })
    return
  }

  await router.push({ name: 'rankings.rules', params: { id: ranking.id } })
}

const openEditModal = (ranking) => {
  successMessage.value = ''
  actionError.value = ''
  editingRanking.value = ranking
  showEditModal.value = true
}

const handleEditClose = () => {
  showEditModal.value = false
  editingRanking.value = null
}

const handleEditSaved = async () => {
  showEditModal.value = false
  editingRanking.value = null
  successMessage.value = 'Ranking actualizado correctamente.'
  await loadRankings()
}

const handleToggleActive = async (ranking) => {
  if (ranking.active && hasHistory(ranking)) {
    const confirmed = window.confirm(RANKING_DEACTIVATE_HISTORY_NOTE)

    if (!confirmed) {
      return
    }
  }

  actionLoadingId.value = ranking.id
  actionError.value = ''
  successMessage.value = ''

  try {
    await RankingService.update(ranking.id, { active: !ranking.active })
    successMessage.value = ranking.active
      ? 'Ranking desactivado correctamente.'
      : 'Ranking activado correctamente.'
    await loadRankings()
  } catch (error) {
    actionError.value = extractApiErrorMessage(error, 'No se pudo actualizar el estado del ranking.')
  } finally {
    actionLoadingId.value = null
  }
}

const handleDelete = async (ranking) => {
  const confirmed = window.confirm(
    `¿Eliminar ${ranking.name || 'este ranking'}? Esta acción no se puede deshacer.`,
  )

  if (!confirmed) {
    return
  }

  actionLoadingId.value = ranking.id
  actionError.value = ''
  successMessage.value = ''

  try {
    await RankingService.delete(ranking.id)
    successMessage.value = 'Ranking eliminado correctamente.'
    await loadRankings()
  } catch (error) {
    actionError.value = extractApiErrorMessage(error, 'No se pudo eliminar el ranking.')
  } finally {
    actionLoadingId.value = null
  }
}

onMounted(loadRankings)
</script>

<template>
  <section class="min-w-0 space-y-4">
    <AppBreadcrumbs />

    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Rankings</h1>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Consultá las tablas de puntuación acumulada.
        </p>
      </div>

      <AppTooltip v-if="canManageRankings" label="Nuevo ranking">
        <button
          type="button"
          :class="addButtonClasses"
          aria-label="Nuevo ranking"
          @click="openCreateModal"
        >
          <PlusIcon class="h-4 w-4" aria-hidden="true" />
        </button>
      </AppTooltip>
    </div>

    <p
      v-if="successMessage"
      class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
    >
      {{ successMessage }}
    </p>

    <p v-if="actionError" class="text-sm text-red-600 dark:text-red-400">{{ actionError }}</p>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando rankings...</p>

    <div v-else-if="errorMessage" class="space-y-3">
      <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
      <button type="button" :class="retryButtonClasses" @click="loadRankings">Reintentar</button>
    </div>

    <div
      v-else-if="rankings.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      Todavía no hay rankings cargados.
    </div>

    <div
      v-else
      class="w-full overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
      <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
        <thead class="bg-slate-50 dark:bg-slate-800">
          <tr>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Ranking
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Modalidad
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Temporada
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Categoría
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Estado
            </th>
            <th
              class="w-52 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Acciones
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
          <tr
            v-for="ranking in rankings"
            :key="ranking.id"
            class="hover:bg-slate-50 dark:hover:bg-slate-800"
          >
            <td class="px-4 py-3 text-sm">
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ ranking.name }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ rankingTypeLabel(ranking.competition_type) }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ ranking.season || '-' }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ rankingCategoryLabel(ranking.category) }}
            </td>
            <td class="px-4 py-3 text-sm">
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="rankingStatusBadgeClasses(ranking.active)"
              >
                {{ rankingStatusLabel(ranking.active) }}
              </span>
            </td>
            <td class="w-52 px-4 py-3 text-sm">
              <div class="flex flex-nowrap items-center justify-end gap-1.5">
                <AppTooltip label="Ver ranking">
                  <RouterLink
                    :to="{ name: 'rankings.show', params: { id: ranking.id } }"
                    :class="viewButtonClasses"
                    aria-label="Ver ranking"
                  >
                    <EyeIcon class="h-4 w-4" aria-hidden="true" />
                  </RouterLink>
                </AppTooltip>

                <template v-if="canManageRankings">
                  <AppTooltip label="Editar">
                    <button
                      type="button"
                      :class="editButtonClasses"
                      aria-label="Editar ranking"
                      :disabled="actionLoadingId === ranking.id"
                      @click="openEditModal(ranking)"
                    >
                      <PencilSquareIcon class="h-4 w-4" aria-hidden="true" />
                    </button>
                  </AppTooltip>

                  <AppTooltip :label="ranking.active ? 'Desactivar' : 'Activar'">
                    <button
                      type="button"
                      :class="ranking.active ? deactivateButtonClasses : activateButtonClasses"
                      :aria-label="ranking.active ? 'Desactivar ranking' : 'Activar ranking'"
                      :disabled="actionLoadingId === ranking.id"
                      @click="handleToggleActive(ranking)"
                    >
                      <NoSymbolIcon v-if="ranking.active" class="h-4 w-4" aria-hidden="true" />
                      <CheckCircleIcon v-else class="h-4 w-4" aria-hidden="true" />
                    </button>
                  </AppTooltip>

                  <AppTooltip v-if="!hasHistory(ranking)" label="Eliminar">
                    <button
                      type="button"
                      :class="deleteButtonClasses"
                      aria-label="Eliminar ranking"
                      :disabled="actionLoadingId === ranking.id"
                      @click="handleDelete(ranking)"
                    >
                      <TrashIcon class="h-4 w-4" aria-hidden="true" />
                    </button>
                  </AppTooltip>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <RankingFormModal
      :show="showCreateModal"
      mode="create"
      @close="handleCreateClose"
      @saved="handleCreateSaved"
    />

    <RankingFormModal
      :show="showEditModal"
      mode="edit"
      :ranking="editingRanking"
      @close="handleEditClose"
      @saved="handleEditSaved"
    />
  </section>
</template>
