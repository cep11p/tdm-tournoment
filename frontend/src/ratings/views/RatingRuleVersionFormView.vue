<script setup>
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import { formatDateTime } from '../../shared/utils/formatDateTime'
import RatingSystemService from '../services/RatingSystemService'
import {
  DIFFERENCE_LABELS,
  differenceTableErrors,
  emptyDifferenceRow,
  INACTIVE_PUBLISH_MESSAGE,
  PUBLISH_CONFIRM_MESSAGE,
  PUBLISH_WARNING,
  rowsFromRules,
  rulesFromRows,
  SYMMETRIC_RULE_NOTE,
} from '../utils/differenceTable'
import { ratingVersionStatusBadgeClasses, ratingVersionStatusLabel } from '../utils/ratingDisplay'

const route = useRoute()

const ratingSystem = ref(null)
const version = ref(null)
const rows = ref([])
const isLoading = ref(false)
const isSaving = ref(false)
const isPublishing = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const actionError = ref('')
const apiErrors = ref({})
const publishAttempted = ref(false)

let rowKey = 0

const systemId = computed(() => route.params.id)
const versionId = computed(() => route.params.versionId)
const readOnly = computed(() => version.value?.status === 'published')
const systemInactive = computed(() => ratingSystem.value?.active === false)
const breadcrumbContext = computed(() => ({
  ratingSystemName: ratingSystem.value?.name,
  ratingVersionNumber: version.value?.version,
}))

const activeErrors = computed(() => {
  if (Object.keys(apiErrors.value).length > 0) {
    return apiErrors.value
  }

  if (!publishAttempted.value || readOnly.value) {
    return {}
  }

  return differenceTableErrors(rulesFromRows(rows.value))
})

const messagesFor = (key) => {
  const value = activeErrors.value[key]

  if (Array.isArray(value)) {
    return value
  }

  if (typeof value === 'string' && value) {
    return [value]
  }

  return []
}

const inputClasses =
  'w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100'

const retryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const primaryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const secondaryButtonClasses =
  'rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800'

const nextKey = () => {
  rowKey += 1
  return rowKey
}

const replaceRows = (rules) => {
  rows.value = rowsFromRules(rules).map((row) => ({ ...row, key: nextKey() }))
}

const loadVersion = async () => {
  const id = systemId.value
  const currentVersionId = versionId.value

  if (!id || !currentVersionId) {
    errorMessage.value = 'No se pudo cargar la versión.'
    return
  }

  isLoading.value = true
  errorMessage.value = ''
  apiErrors.value = {}
  publishAttempted.value = false

  try {
    const [system, loaded] = await Promise.all([
      RatingSystemService.show(id),
      RatingSystemService.showVersion(id, currentVersionId),
    ])
    ratingSystem.value = system
    version.value = loaded
    replaceRows(loaded?.rules ?? [])
  } catch {
    errorMessage.value = 'No se pudo cargar la versión de reglas.'
    ratingSystem.value = null
    version.value = null
    rows.value = []
  } finally {
    isLoading.value = false
  }
}

const addRow = () => {
  rows.value.forEach((row) => {
    row.openEnded = false
  })
  rows.value.push({
    ...emptyDifferenceRow(rows.value.length === 0),
    key: nextKey(),
    openEnded: true,
  })
}

const removeRow = (key) => {
  rows.value = rows.value.filter((row) => row.key !== key)
  const last = rows.value.at(-1)

  if (last) {
    last.openEnded = true
  }
}

const handleSave = async () => {
  isSaving.value = true
  actionError.value = ''
  successMessage.value = ''
  apiErrors.value = {}

  try {
    version.value = await RatingSystemService.updateVersion(systemId.value, versionId.value, {
      rules: rulesFromRows(rows.value),
    })
    replaceRows(version.value?.rules ?? [])
    successMessage.value = 'Borrador guardado.'
  } catch (error) {
    apiErrors.value = error?.response?.data?.errors ?? {}
    actionError.value = extractApiErrorMessage(error, 'No se pudo guardar el borrador.')
  } finally {
    isSaving.value = false
  }
}

const handlePublish = async () => {
  publishAttempted.value = true
  apiErrors.value = {}
  actionError.value = ''
  successMessage.value = ''

  if (systemInactive.value) {
    actionError.value = INACTIVE_PUBLISH_MESSAGE
    return
  }

  if (Object.keys(differenceTableErrors(rulesFromRows(rows.value))).length > 0) {
    return
  }

  const confirmed = window.confirm(PUBLISH_CONFIRM_MESSAGE)

  if (!confirmed) {
    return
  }

  isPublishing.value = true

  try {
    await RatingSystemService.updateVersion(systemId.value, versionId.value, {
      rules: rulesFromRows(rows.value),
    })
    version.value = await RatingSystemService.publishVersion(systemId.value, versionId.value)
    replaceRows(version.value?.rules ?? [])
    successMessage.value = 'Versión publicada. Las reglas quedaron fijas.'
  } catch (error) {
    apiErrors.value = error?.response?.data?.errors ?? {}
    actionError.value = extractApiErrorMessage(error, 'No se pudo publicar la versión.')
  } finally {
    isPublishing.value = false
  }
}

watch([systemId, versionId], loadVersion, { immediate: true })
</script>

<template>
  <section class="min-w-0 space-y-4">
    <AppBreadcrumbs :context="breadcrumbContext" />

    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
          Versión {{ version?.version ?? '' }}
        </h1>
        <p class="mt-1 text-lg font-medium text-slate-800 dark:text-slate-200">
          {{ ratingSystem?.name || 'Sistema de rating' }}
        </p>
        <div v-if="version" class="mt-2 flex flex-wrap items-center gap-2 text-sm">
          <span
            class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
            :class="ratingVersionStatusBadgeClasses(version.status)"
          >
            {{ ratingVersionStatusLabel(version.status) }}
          </span>
          <span class="text-slate-600 dark:text-slate-400">
            Publicación: {{ version.published_at ? formatDateTime(version.published_at) : '—' }}
          </span>
        </div>
      </div>
      <AppBackButton :fallback-to="`/rating-systems/${systemId}/versions`" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando versión...</p>

    <div v-else-if="errorMessage" class="space-y-3">
      <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
      <button type="button" :class="retryButtonClasses" @click="loadVersion">Reintentar</button>
    </div>

    <template v-else-if="version">
      <p
        v-if="successMessage"
        class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
      >
        {{ successMessage }}
      </p>
      <p v-if="actionError" class="text-sm text-red-600 dark:text-red-400">{{ actionError }}</p>

      <div
        class="rounded-md border p-4 text-sm"
        :class="
          readOnly
            ? 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'
            : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100'
        "
      >
        {{
          readOnly
            ? 'Esta versión está publicada y no se puede modificar.'
            : systemInactive
              ? INACTIVE_PUBLISH_MESSAGE
              : PUBLISH_WARNING
        }}
      </div>

      <p class="text-sm text-slate-600 dark:text-slate-400">{{ SYMMETRIC_RULE_NOTE }}</p>

      <p
        v-for="message in messagesFor('rules')"
        :key="message"
        class="text-sm text-red-600 dark:text-red-400"
      >
        {{ message }}
      </p>

      <div class="overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
          <thead class="bg-slate-50 dark:bg-slate-800">
            <tr>
              <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                Diferencia de rating · {{ DIFFERENCE_LABELS.min }}
              </th>
              <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                Diferencia de rating · {{ DIFFERENCE_LABELS.max }}
              </th>
              <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                {{ DIFFERENCE_LABELS.favorite }}
              </th>
              <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                {{ DIFFERENCE_LABELS.underdog }}
              </th>
              <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                Acciones
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
            <tr v-if="rows.length === 0">
              <td colspan="5" class="px-3 py-4 text-sm text-slate-600 dark:text-slate-300">
                Sin rangos cargados.
              </td>
            </tr>
            <tr v-for="(row, index) in rows" :key="row.key">
              <td class="px-3 py-3 align-top">
                <label class="sr-only" :for="`min-${row.key}`">{{ DIFFERENCE_LABELS.min }}</label>
                <input
                  :id="`min-${row.key}`"
                  v-model="row.min"
                  type="number"
                  min="0"
                  step="1"
                  :class="inputClasses"
                  :disabled="readOnly"
                />
                <p
                  v-for="message in messagesFor(`rules.${index}.min`)"
                  :key="message"
                  class="mt-1 text-xs text-red-600 dark:text-red-400"
                >
                  {{ message }}
                </p>
              </td>
              <td class="px-3 py-3 align-top">
                <label class="sr-only" :for="`max-${row.key}`">{{ DIFFERENCE_LABELS.max }}</label>
                <input
                  :id="`max-${row.key}`"
                  v-model="row.max"
                  type="number"
                  min="0"
                  step="1"
                  :class="inputClasses"
                  :disabled="readOnly || (index === rows.length - 1 && row.openEnded)"
                  :placeholder="index === rows.length - 1 && row.openEnded ? 'Sin límite' : ''"
                />
                <label
                  v-if="index === rows.length - 1"
                  class="mt-2 flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"
                >
                  <input v-model="row.openEnded" type="checkbox" :disabled="readOnly" />
                  Sin límite superior
                </label>
                <p
                  v-for="message in [...messagesFor(`rules.${index}.max`), ...messagesFor(`rules.${index}`)]"
                  :key="message"
                  class="mt-1 text-xs text-red-600 dark:text-red-400"
                >
                  {{ message }}
                </p>
              </td>
              <td class="px-3 py-3 align-top">
                <label class="sr-only" :for="`favorite-${row.key}`">{{ DIFFERENCE_LABELS.favorite }}</label>
                <input
                  :id="`favorite-${row.key}`"
                  v-model="row.favoriteWin"
                  type="number"
                  min="0"
                  step="1"
                  :class="inputClasses"
                  :disabled="readOnly"
                />
                <p
                  v-for="message in messagesFor(`rules.${index}.favorite_win`)"
                  :key="message"
                  class="mt-1 text-xs text-red-600 dark:text-red-400"
                >
                  {{ message }}
                </p>
              </td>
              <td class="px-3 py-3 align-top">
                <label class="sr-only" :for="`underdog-${row.key}`">{{ DIFFERENCE_LABELS.underdog }}</label>
                <input
                  :id="`underdog-${row.key}`"
                  v-model="row.underdogWin"
                  type="number"
                  min="0"
                  step="1"
                  :class="inputClasses"
                  :disabled="readOnly"
                />
                <p
                  v-for="message in messagesFor(`rules.${index}.underdog_win`)"
                  :key="message"
                  class="mt-1 text-xs text-red-600 dark:text-red-400"
                >
                  {{ message }}
                </p>
              </td>
              <td class="px-3 py-3 align-top">
                <button
                  v-if="!readOnly"
                  type="button"
                  class="inline-flex rounded-md border border-red-300 bg-red-50 p-1.5 text-red-700 hover:bg-red-100 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
                  aria-label="Quitar rango"
                  @click="removeRow(row.key)"
                >
                  <TrashIcon class="h-4 w-4" aria-hidden="true" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="!readOnly" class="flex flex-wrap items-center justify-between gap-3">
        <button type="button" :class="secondaryButtonClasses" @click="addRow">
          <span class="inline-flex items-center gap-1">
            <PlusIcon class="h-4 w-4" aria-hidden="true" />
            Agregar rango
          </span>
        </button>
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            :class="secondaryButtonClasses"
            :disabled="isSaving || isPublishing"
            @click="handleSave"
          >
            {{ isSaving ? 'Guardando...' : 'Guardar borrador' }}
          </button>
          <button
            type="button"
            :class="primaryButtonClasses"
            :disabled="isSaving || isPublishing || systemInactive"
            @click="handlePublish"
          >
            {{ isPublishing ? 'Publicando...' : 'Publicar' }}
          </button>
        </div>
      </div>
    </template>
  </section>
</template>
