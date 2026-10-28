<script setup>
import {
  CheckCircleIcon,
  ListBulletIcon,
  NoSymbolIcon,
  PencilSquareIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline'
import { computed, onMounted, ref } from 'vue'

import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import AppTooltip from '../../components/AppTooltip.vue'
import { usePermissions } from '../../composables/usePermissions'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import RatingSystemFormModal from '../components/RatingSystemFormModal.vue'
import RatingSystemService from '../services/RatingSystemService'
import { ratingMethodLabel, ratingStatusBadgeClasses, ratingStatusLabel } from '../utils/ratingDisplay'

const { can } = usePermissions()
const canManageRatings = computed(() => can('ratings.manage'))

const ratingSystems = ref([])
const isLoading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const actionError = ref('')
const actionLoadingId = ref(null)
const showCreateModal = ref(false)
const showEditModal = ref(false)
const editingRatingSystem = ref(null)

const addButtonClasses =
  'inline-flex rounded-md border border-emerald-300 bg-emerald-50 p-2 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60'

const versionsButtonClasses =
  'inline-flex rounded-md border border-sky-300 bg-sky-50 p-1.5 text-sky-800 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-950/60'

const editButtonClasses =
  'inline-flex rounded-md border border-amber-300 bg-amber-50 p-1.5 text-amber-800 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60'

const activateButtonClasses =
  'inline-flex rounded-md border border-emerald-300 bg-emerald-50 p-1.5 text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60'

const deactivateButtonClasses =
  'inline-flex rounded-md border border-slate-300 bg-slate-50 p-1.5 text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'

const retryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const loadRatingSystems = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    ratingSystems.value = await RatingSystemService.index()
  } catch {
    errorMessage.value = 'No se pudieron cargar los sistemas de rating.'
    ratingSystems.value = []
  } finally {
    isLoading.value = false
  }
}

const openCreateModal = () => {
  successMessage.value = ''
  actionError.value = ''
  showCreateModal.value = true
}

const handleCreateSaved = async () => {
  showCreateModal.value = false
  successMessage.value = 'Sistema de rating creado correctamente.'
  await loadRatingSystems()
}

const openEditModal = (ratingSystem) => {
  successMessage.value = ''
  actionError.value = ''
  editingRatingSystem.value = ratingSystem
  showEditModal.value = true
}

const handleEditClose = () => {
  showEditModal.value = false
  editingRatingSystem.value = null
}

const handleEditSaved = async () => {
  showEditModal.value = false
  editingRatingSystem.value = null
  successMessage.value = 'Sistema de rating actualizado correctamente.'
  await loadRatingSystems()
}

const handleToggleActive = async (ratingSystem) => {
  actionLoadingId.value = ratingSystem.id
  actionError.value = ''
  successMessage.value = ''

  try {
    await RatingSystemService.update(ratingSystem.id, { active: !ratingSystem.active })
    successMessage.value = ratingSystem.active
      ? 'Sistema de rating desactivado correctamente.'
      : 'Sistema de rating activado correctamente.'
    await loadRatingSystems()
  } catch (error) {
    actionError.value = extractApiErrorMessage(
      error,
      'No se pudo actualizar el estado del sistema de rating.',
    )
  } finally {
    actionLoadingId.value = null
  }
}

onMounted(loadRatingSystems)
</script>

<template>
  <section class="min-w-0 space-y-4">
    <AppBreadcrumbs />

    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Sistemas de rating</h1>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Administrá los sistemas de puntuación.
        </p>
      </div>

      <AppTooltip v-if="canManageRatings" label="Nuevo sistema de rating">
        <button
          type="button"
          :class="addButtonClasses"
          aria-label="Nuevo sistema de rating"
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

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">
      Cargando sistemas de rating...
    </p>

    <div v-else-if="errorMessage" class="space-y-3">
      <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
      <button type="button" :class="retryButtonClasses" @click="loadRatingSystems">Reintentar</button>
    </div>

    <div
      v-else-if="ratingSystems.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      Todavía no hay sistemas de rating cargados.
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
              Nombre
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Descripción
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Rating inicial
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Método
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Estado
            </th>
            <th
              class="w-44 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Acciones
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
          <tr
            v-for="ratingSystem in ratingSystems"
            :key="ratingSystem.id"
            class="hover:bg-slate-50 dark:hover:bg-slate-800"
          >
            <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">
              {{ ratingSystem.name }}
            </td>
            <td class="max-w-xs px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ ratingSystem.description || '—' }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ ratingSystem.initial_rating }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ ratingMethodLabel(ratingSystem.method) }}
            </td>
            <td class="px-4 py-3 text-sm">
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="ratingStatusBadgeClasses(ratingSystem.active)"
              >
                {{ ratingStatusLabel(ratingSystem.active) }}
              </span>
            </td>
            <td class="w-44 px-4 py-3 text-sm">
              <div v-if="canManageRatings" class="flex flex-nowrap items-center justify-end gap-1.5">
                <AppTooltip label="Versiones de reglas">
                  <RouterLink
                    :to="`/rating-systems/${ratingSystem.id}/versions`"
                    :class="versionsButtonClasses"
                    aria-label="Versiones de reglas"
                  >
                    <ListBulletIcon class="h-4 w-4" aria-hidden="true" />
                  </RouterLink>
                </AppTooltip>

                <AppTooltip label="Editar">
                  <button
                    type="button"
                    :class="editButtonClasses"
                    aria-label="Editar sistema de rating"
                    :disabled="actionLoadingId === ratingSystem.id"
                    @click="openEditModal(ratingSystem)"
                  >
                    <PencilSquareIcon class="h-4 w-4" aria-hidden="true" />
                  </button>
                </AppTooltip>

                <AppTooltip :label="ratingSystem.active ? 'Desactivar' : 'Activar'">
                  <button
                    type="button"
                    :class="ratingSystem.active ? deactivateButtonClasses : activateButtonClasses"
                    :aria-label="
                      ratingSystem.active
                        ? 'Desactivar sistema de rating'
                        : 'Activar sistema de rating'
                    "
                    :disabled="actionLoadingId === ratingSystem.id"
                    @click="handleToggleActive(ratingSystem)"
                  >
                    <NoSymbolIcon v-if="ratingSystem.active" class="h-4 w-4" aria-hidden="true" />
                    <CheckCircleIcon v-else class="h-4 w-4" aria-hidden="true" />
                  </button>
                </AppTooltip>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <RatingSystemFormModal
      :show="showCreateModal"
      mode="create"
      @close="showCreateModal = false"
      @saved="handleCreateSaved"
    />

    <RatingSystemFormModal
      :show="showEditModal"
      mode="edit"
      :rating-system="editingRatingSystem"
      @close="handleEditClose"
      @saved="handleEditSaved"
    />
  </section>
</template>
