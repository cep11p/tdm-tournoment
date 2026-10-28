<script setup>
import { ArrowUpCircleIcon, EyeIcon, PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import AppTooltip from '../../components/AppTooltip.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import { formatDateTime } from '../../shared/utils/formatDateTime'
import RatingSystemService from '../services/RatingSystemService'
import {
  DIFFERENCE_LABELS,
  INACTIVE_PUBLISH_MESSAGE,
  PUBLISH_CONFIRM_MESSAGE,
  PUBLISH_WARNING,
  rangeSpanLabel,
} from '../utils/differenceTable'
import { ratingVersionStatusBadgeClasses, ratingVersionStatusLabel } from '../utils/ratingDisplay'

const route = useRoute()
const router = useRouter()

const ratingSystem = ref(null)
const versions = ref([])
const isLoading = ref(false)
const isCreating = ref(false)
const publishingId = ref(null)
const errorMessage = ref('')
const successMessage = ref('')
const actionError = ref('')

const systemId = computed(() => route.params.id)
const breadcrumbContext = computed(() => ({
  ratingSystemName: ratingSystem.value?.name,
}))

const retryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const addButtonClasses =
  'inline-flex rounded-md border border-emerald-300 bg-emerald-50 p-2 text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60'

const editButtonClasses =
  'inline-flex rounded-md border border-amber-300 bg-amber-50 p-1.5 text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60'

const viewButtonClasses =
  'inline-flex rounded-md border border-slate-300 bg-slate-50 p-1.5 text-slate-700 hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'

const publishButtonClasses =
  'inline-flex rounded-md border border-emerald-300 bg-emerald-50 p-1.5 text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60'

const loadVersions = async () => {
  const id = systemId.value

  if (!id) {
    errorMessage.value = 'No se pudo cargar el sistema de rating.'
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    const [system, versionList] = await Promise.all([
      RatingSystemService.show(id),
      RatingSystemService.versions(id),
    ])
    ratingSystem.value = system
    versions.value = versionList
  } catch {
    errorMessage.value = 'No se pudieron cargar las versiones de reglas.'
    ratingSystem.value = null
    versions.value = []
  } finally {
    isLoading.value = false
  }
}

const handleCreate = async () => {
  isCreating.value = true
  actionError.value = ''
  successMessage.value = ''

  try {
    const created = await RatingSystemService.createVersion(systemId.value, { rules: [] })
    await router.push(`/rating-systems/${systemId.value}/versions/${created.id}`)
  } catch (error) {
    actionError.value = extractApiErrorMessage(error, 'No se pudo crear la versión.')
  } finally {
    isCreating.value = false
  }
}

const handlePublish = async (version) => {
  if (ratingSystem.value?.active === false) {
    actionError.value = INACTIVE_PUBLISH_MESSAGE
    return
  }

  const confirmed = window.confirm(PUBLISH_CONFIRM_MESSAGE)

  if (!confirmed) {
    return
  }

  publishingId.value = version.id
  actionError.value = ''
  successMessage.value = ''

  try {
    await RatingSystemService.publishVersion(systemId.value, version.id)
    successMessage.value = `Versión ${version.version} publicada. Las reglas quedaron fijas.`
    await loadVersions()
  } catch (error) {
    actionError.value = extractApiErrorMessage(error, 'No se pudo publicar la versión.')
  } finally {
    publishingId.value = null
  }
}

watch(systemId, loadVersions, { immediate: true })
</script>

<template>
  <section class="min-w-0 space-y-4">
    <AppBreadcrumbs :context="breadcrumbContext" />

    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Versiones de reglas</h1>
        <p class="mt-1 text-lg font-medium text-slate-800 dark:text-slate-200">
          {{ ratingSystem?.name || 'Sistema de rating' }}
        </p>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ PUBLISH_WARNING }}</p>
        <p
          v-if="ratingSystem && ratingSystem.active === false"
          class="mt-2 text-sm text-amber-800 dark:text-amber-200"
        >
          {{ INACTIVE_PUBLISH_MESSAGE }}
        </p>
      </div>

      <div class="flex items-center gap-2">
        <AppTooltip label="Nueva versión">
          <button
            type="button"
            :class="addButtonClasses"
            aria-label="Nueva versión"
            :disabled="isCreating"
            @click="handleCreate"
          >
            <PlusIcon class="h-4 w-4" aria-hidden="true" />
          </button>
        </AppTooltip>
        <AppBackButton fallback-to="/rating-systems" />
      </div>
    </div>

    <p
      v-if="successMessage"
      class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
    >
      {{ successMessage }}
    </p>
    <p v-if="actionError" class="text-sm text-red-600 dark:text-red-400">{{ actionError }}</p>
    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando versiones...</p>

    <div v-else-if="errorMessage" class="space-y-3">
      <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
      <button type="button" :class="retryButtonClasses" @click="loadVersions">Reintentar</button>
    </div>

    <div
      v-else-if="versions.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      Este sistema todavía no tiene versiones de reglas.
    </div>

    <div v-else class="space-y-4">
      <article
        v-for="version in versions"
        :key="version.id"
        class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="space-y-1">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                Versión {{ version.version }}
              </h2>
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="ratingVersionStatusBadgeClasses(version.status)"
              >
                {{ ratingVersionStatusLabel(version.status) }}
              </span>
            </div>
            <p class="text-sm text-slate-600 dark:text-slate-400">
              Publicación: {{ version.published_at ? formatDateTime(version.published_at) : '—' }}
            </p>
          </div>

          <div class="flex items-center gap-1.5">
            <AppTooltip v-if="version.status === 'draft'" label="Editar">
              <RouterLink
                :to="`/rating-systems/${systemId}/versions/${version.id}`"
                :class="editButtonClasses"
                aria-label="Editar versión"
              >
                <PencilSquareIcon class="h-4 w-4" aria-hidden="true" />
              </RouterLink>
            </AppTooltip>
            <AppTooltip v-else label="Consultar">
              <RouterLink
                :to="`/rating-systems/${systemId}/versions/${version.id}`"
                :class="viewButtonClasses"
                aria-label="Consultar versión"
              >
                <EyeIcon class="h-4 w-4" aria-hidden="true" />
              </RouterLink>
            </AppTooltip>
            <AppTooltip v-if="version.status === 'draft'" :label="ratingSystem?.active === false ? INACTIVE_PUBLISH_MESSAGE : 'Publicar'">
              <button
                type="button"
                :class="publishButtonClasses"
                aria-label="Publicar versión"
                :disabled="publishingId === version.id || ratingSystem?.active === false"
                @click="handlePublish(version)"
              >
                <ArrowUpCircleIcon class="h-4 w-4" aria-hidden="true" />
              </button>
            </AppTooltip>
          </div>
        </div>

        <p v-if="!version.rules?.length" class="mt-3 text-sm text-slate-600 dark:text-slate-300">
          Sin rangos cargados.
        </p>
        <div v-else class="mt-3 overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <thead>
              <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                <th class="px-2 py-2">Diferencia de rating</th>
                <th class="px-2 py-2">{{ DIFFERENCE_LABELS.favorite }}</th>
                <th class="px-2 py-2">{{ DIFFERENCE_LABELS.underdog }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
              <tr v-for="(range, index) in version.rules" :key="`${version.id}-${index}`">
                <td class="px-2 py-2 text-slate-800 dark:text-slate-100">{{ rangeSpanLabel(range) }}</td>
                <td class="px-2 py-2 text-slate-800 dark:text-slate-100">{{ range.favorite_win }}</td>
                <td class="px-2 py-2 text-slate-800 dark:text-slate-100">{{ range.underdog_win }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </div>
  </section>
</template>
