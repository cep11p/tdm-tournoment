<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import RankingRuleFormModal from '../components/RankingRuleFormModal.vue'
import RankingService from '../services/RankingService'
import {
  rankingCategoryLabel,
  rankingStatusBadgeClasses,
  rankingTypeLabel,
} from '../utils/rankingDisplay'
import {
  rankingRuleResultOptions,
  rankingRuleStatusLabel,
  RANKING_RULE_SHARED_THIRD_NOTE,
  RANKING_RULES_LOCKED_NOTE,
} from '../utils/rankingRuleCatalog'

const route = useRoute()

const ranking = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const actionError = ref('')
const isDeletingId = ref(null)
const showForm = ref(false)
const formMode = ref('create')
const selectedRule = ref(null)

const retryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const primaryButtonClasses =
  'rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200'

const rankingId = computed(() => route.params.id)
const rulesLocked = computed(() => ranking.value?.rules_locked === true)
const backFallback = computed(() =>
  rankingId.value ? `/rankings/${rankingId.value}` : '/rankings',
)

const breadcrumbContext = computed(() => ({
  rankingName: ranking.value?.name,
}))

const catalogOrder = rankingRuleResultOptions.map((option) => option.key)

const sortedRules = computed(() => {
  const rules = Array.isArray(ranking.value?.rules) ? [...ranking.value.rules] : []

  return rules.sort((left, right) => {
    const leftIndex = catalogOrder.indexOf(left.result_key)
    const rightIndex = catalogOrder.indexOf(right.result_key)
    const safeLeft = leftIndex === -1 ? catalogOrder.length : leftIndex
    const safeRight = rightIndex === -1 ? catalogOrder.length : rightIndex

    if (safeLeft !== safeRight) {
      return safeLeft - safeRight
    }

    return (left.id || 0) - (right.id || 0)
  })
})

const usedKeys = computed(() =>
  sortedRules.value.map((rule) => rule.result_key).filter(Boolean),
)

const canCreateRule = computed(
  () => !rulesLocked.value && usedKeys.value.length < rankingRuleResultOptions.length,
)

const loadRanking = async () => {
  const id = rankingId.value

  if (!id) {
    ranking.value = null
    errorMessage.value = 'No se pudo cargar el ranking.'
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    ranking.value = await RankingService.show(id)
  } catch {
    errorMessage.value = 'No se pudo cargar el ranking.'
    ranking.value = null
  } finally {
    isLoading.value = false
  }
}

const openCreate = () => {
  formMode.value = 'create'
  selectedRule.value = null
  showForm.value = true
  actionError.value = ''
}

const openEdit = (rule) => {
  formMode.value = 'edit'
  selectedRule.value = rule
  showForm.value = true
  actionError.value = ''
}

const closeForm = () => {
  showForm.value = false
  selectedRule.value = null
}

const handleSaved = async () => {
  const wasCreate = formMode.value === 'create'
  closeForm()
  await loadRanking()
  successMessage.value = wasCreate
    ? 'Regla creada correctamente.'
    : 'Regla actualizada correctamente.'
  actionError.value = ''
}

const handleDelete = async (rule) => {
  if (rulesLocked.value || !rankingId.value) {
    return
  }

  const confirmed = window.confirm('¿Eliminar esta regla de puntuación?')

  if (!confirmed) {
    return
  }

  isDeletingId.value = rule.id
  actionError.value = ''
  successMessage.value = ''

  try {
    await RankingService.deleteRule(rankingId.value, rule.id)
    await loadRanking()
    successMessage.value = 'Regla eliminada correctamente.'
  } catch (error) {
    actionError.value = extractApiErrorMessage(error, 'No se pudo eliminar la regla.')
  } finally {
    isDeletingId.value = null
  }
}

watch(rankingId, loadRanking, { immediate: true })
</script>

<template>
  <section class="min-w-0 space-y-4">
    <AppBreadcrumbs :context="breadcrumbContext" />

    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
          Reglas de puntuación
        </h1>
        <p class="mt-1 text-lg font-medium text-slate-800 dark:text-slate-200">
          {{ ranking?.name || 'Ranking' }}
        </p>
        <p
          v-if="ranking"
          class="mt-1 text-sm text-slate-600 dark:text-slate-400"
        >
          {{ rankingTypeLabel(ranking.competition_type) }}
          <template v-if="ranking.season"> · Temporada {{ ranking.season }}</template>
        </p>
        <p v-if="ranking" class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">
          {{ rankingCategoryLabel(ranking.category) }}
        </p>
      </div>

      <AppBackButton :fallback-to="backFallback" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando reglas...</p>

    <div v-else-if="errorMessage" class="space-y-3">
      <p class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>
      <button type="button" :class="retryButtonClasses" @click="loadRanking">Reintentar</button>
    </div>

    <template v-else-if="ranking">
      <p
        v-if="successMessage"
        class="text-sm text-emerald-700 dark:text-emerald-300"
      >
        {{ successMessage }}
      </p>
      <p v-if="actionError" class="text-sm text-red-600 dark:text-red-400">{{ actionError }}</p>

      <div
        v-if="rulesLocked"
        class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
      >
        {{ RANKING_RULES_LOCKED_NOTE }}
      </div>

      <p class="text-sm text-slate-600 dark:text-slate-400">
        {{ RANKING_RULE_SHARED_THIRD_NOTE }}
      </p>

      <div v-if="canCreateRule" class="flex justify-end">
        <button
          type="button"
          :class="primaryButtonClasses"
          @click="openCreate"
        >
          Nueva regla
        </button>
      </div>

      <div
        v-if="sortedRules.length === 0"
        class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
      >
        Este ranking todavía no tiene reglas de puntuación.
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
                Resultado
              </th>
              <th
                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Puntos
              </th>
              <th
                class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Estado
              </th>
              <th
                v-if="!rulesLocked"
                class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
              >
                Acciones
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
            <tr
              v-for="rule in sortedRules"
              :key="rule.id"
              class="hover:bg-slate-50 dark:hover:bg-slate-800"
            >
              <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">
                {{ rule.name }}
              </td>
              <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
                {{ rule.points }} pts
              </td>
              <td class="px-4 py-3 text-sm">
                <span
                  class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                  :class="rankingStatusBadgeClasses(rule.active)"
                >
                  {{ rankingRuleStatusLabel(rule.active) }}
                </span>
              </td>
              <td v-if="!rulesLocked" class="px-4 py-3 text-sm">
                <div class="flex flex-nowrap items-center justify-end gap-2">
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                    @click="openEdit(rule)"
                  >
                    Editar
                  </button>
                  <button
                    type="button"
                    class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-60 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40"
                    :disabled="isDeletingId === rule.id"
                    @click="handleDelete(rule)"
                  >
                    Eliminar
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <RankingRuleFormModal
      :show="showForm"
      :mode="formMode"
      :ranking-id="rankingId"
      :ranking="ranking"
      :rule="selectedRule"
      @close="closeForm"
      @saved="handleSaved"
    />
  </section>
</template>
