<script setup>
import { computed, reactive, ref, watch } from 'vue'

import CategoryService from '../../categories/services/CategoryService'
import BaseModal from '../../shared/components/BaseModal.vue'
import { getCompetitionTypeLabel } from '../../shared/constants/competitionType'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import RankingService from '../services/RankingService'
import {
  RANKING_ACTIVATE_NEEDS_RULES_NOTE,
  RANKING_STRUCTURAL_LOCKED_NOTE,
} from '../utils/rankingDisplay'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  mode: {
    type: String,
    default: 'create',
    validator: (value) => ['create', 'edit'].includes(value),
  },
  ranking: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const isSubmitting = ref(false)
const isLoadingOptions = ref(false)
const errors = ref({})
const errorMessage = ref('')
const categories = ref([])
const rankings = ref([])

const form = reactive({
  name: '',
  competition_type: 'singles',
  category_id: '',
  season: '',
  starts_at: '',
  ends_at: '',
  active: false,
  copy_rules_from_ranking_id: '',
})

const isCreate = computed(() => props.mode === 'create')
const hasHistory = computed(
  () => props.ranking?.has_history === true || props.ranking?.rules_locked === true,
)
const scopeLocked = computed(() => !isCreate.value && hasHistory.value)

const copyOptions = computed(() =>
  rankings.value.filter((ranking) => ranking.competition_type === form.competition_type),
)

const canEnableActiveOnCreate = computed(
  () => isCreate.value && Boolean(form.copy_rules_from_ranking_id),
)

const modalTitle = computed(() =>
  isCreate.value ? 'Nuevo ranking' : props.ranking?.name ? `Editar ${props.ranking.name}` : 'Editar ranking',
)

const modalDescription = computed(() =>
  isCreate.value
    ? 'Creá un ranking para una nueva temporada o reglamento.'
    : 'Actualizá los datos del ranking.',
)

const submitLabel = computed(() => (isCreate.value ? 'Crear ranking' : 'Guardar cambios'))

const inputClasses =
  'w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100'

const fieldError = (field) => errors.value?.[field]?.[0] ?? ''

const resetState = () => {
  isSubmitting.value = false
  isLoadingOptions.value = false
  errors.value = {}
  errorMessage.value = ''
  form.name = ''
  form.competition_type = 'singles'
  form.category_id = ''
  form.season = ''
  form.starts_at = ''
  form.ends_at = ''
  form.active = false
  form.copy_rules_from_ranking_id = ''
}

const syncForm = () => {
  if (isCreate.value || !props.ranking) {
    form.name = ''
    form.competition_type = 'singles'
    form.category_id = ''
    form.season = ''
    form.starts_at = ''
    form.ends_at = ''
    form.active = false
    form.copy_rules_from_ranking_id = ''
    return
  }

  form.name = props.ranking.name ?? ''
  form.competition_type = props.ranking.competition_type ?? 'singles'
  form.category_id = props.ranking.category?.id ? String(props.ranking.category.id) : ''
  form.season = props.ranking.season ?? ''
  form.starts_at = props.ranking.starts_at ?? ''
  form.ends_at = props.ranking.ends_at ?? ''
  form.active = props.ranking.active === true
  form.copy_rules_from_ranking_id = ''
}

const loadOptions = async () => {
  isLoadingOptions.value = true

  try {
    const [categoryRows, rankingRows] = await Promise.all([
      CategoryService.list(),
      RankingService.index(),
    ])

    categories.value = categoryRows ?? []
    rankings.value = rankingRows ?? []
  } catch {
    categories.value = []
    rankings.value = []
  } finally {
    isLoadingOptions.value = false
  }
}

const handleClose = () => {
  if (isSubmitting.value) {
    return
  }

  emit('close')
}

const buildPayload = () => {
  if (scopeLocked.value) {
    return {
      name: form.name.trim(),
      active: Boolean(form.active),
    }
  }

  const payload = {
    name: form.name.trim(),
    competition_type: form.competition_type,
    category_id: form.category_id === '' ? null : Number(form.category_id),
    season: form.season.trim(),
    starts_at: form.starts_at || null,
    ends_at: form.ends_at || null,
    active: isCreate.value && !canEnableActiveOnCreate.value ? false : Boolean(form.active),
  }

  if (isCreate.value && form.copy_rules_from_ranking_id) {
    payload.copy_rules_from_ranking_id = Number(form.copy_rules_from_ranking_id)
  }

  return payload
}

const handleSubmit = async () => {
  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    const payload = buildPayload()
    const result = isCreate.value
      ? await RankingService.create(payload)
      : await RankingService.update(props.ranking.id, payload)

    emit('saved', result, {
      copied: Boolean(payload.copy_rules_from_ranking_id),
    })
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value = extractApiErrorMessage(
      error,
      isCreate.value ? 'No se pudo crear el ranking.' : 'No se pudo actualizar el ranking.',
    )
  } finally {
    isSubmitting.value = false
  }
}

watch(
  () => form.competition_type,
  () => {
    if (!isCreate.value) {
      return
    }

    const selected = copyOptions.value.find(
      (ranking) => String(ranking.id) === String(form.copy_rules_from_ranking_id),
    )

    if (!selected) {
      form.copy_rules_from_ranking_id = ''
      form.active = false
    }
  },
)

watch(
  () => form.copy_rules_from_ranking_id,
  (value) => {
    if (!value) {
      form.active = false
    }
  },
)

watch(
  () => props.show,
  async (isVisible) => {
    if (!isVisible) {
      resetState()
      return
    }

    resetState()
    syncForm()
    await loadOptions()
  },
)
</script>

<template>
  <BaseModal
    :show="show"
    :title="modalTitle"
    :description="modalDescription"
    size="lg"
    :prevent-close="isSubmitting"
    @close="handleClose"
  >
    <p v-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>
    <p v-if="errors.ranking?.[0]" class="text-red-600 dark:text-red-400">{{ errors.ranking[0] }}</p>

    <form class="space-y-4" @submit.prevent="handleSubmit">
      <p
        v-if="scopeLocked"
        class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
      >
        {{ RANKING_STRUCTURAL_LOCKED_NOTE }}
      </p>

      <label class="block space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Nombre</span>
        <input
          v-model="form.name"
          type="text"
          maxlength="255"
          :class="inputClasses"
          :disabled="isSubmitting"
          required
        />
        <span v-if="fieldError('name')" class="text-red-600 dark:text-red-400">
          {{ fieldError('name') }}
        </span>
      </label>

      <label class="block space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Modalidad</span>
        <select
          v-model="form.competition_type"
          :class="inputClasses"
          :disabled="isSubmitting || scopeLocked"
          required
        >
          <option value="singles">{{ getCompetitionTypeLabel('singles') }}</option>
          <option value="doubles">{{ getCompetitionTypeLabel('doubles') }}</option>
        </select>
        <span v-if="fieldError('competition_type')" class="text-red-600 dark:text-red-400">
          {{ fieldError('competition_type') }}
        </span>
      </label>

      <label class="block space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Temporada</span>
        <input
          v-model="form.season"
          type="text"
          maxlength="50"
          placeholder="Ej. 2027"
          :class="inputClasses"
          :disabled="isSubmitting || scopeLocked"
          required
        />
        <span v-if="fieldError('season')" class="text-red-600 dark:text-red-400">
          {{ fieldError('season') }}
        </span>
      </label>

      <label class="block space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Categoría</span>
        <select
          v-model="form.category_id"
          :class="inputClasses"
          :disabled="isSubmitting || scopeLocked || isLoadingOptions"
        >
          <option value="">Todas las categorías</option>
          <option v-for="category in categories" :key="category.id" :value="String(category.id)">
            {{ category.name }}
          </option>
        </select>
        <span v-if="fieldError('category_id')" class="text-red-600 dark:text-red-400">
          {{ fieldError('category_id') }}
        </span>
      </label>

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block space-y-1 text-sm">
          <span class="font-medium text-slate-700 dark:text-slate-300">Vigencia desde</span>
          <input
            v-model="form.starts_at"
            type="date"
            :class="inputClasses"
            :disabled="isSubmitting || scopeLocked"
          />
          <span v-if="fieldError('starts_at')" class="text-red-600 dark:text-red-400">
            {{ fieldError('starts_at') }}
          </span>
        </label>

        <label class="block space-y-1 text-sm">
          <span class="font-medium text-slate-700 dark:text-slate-300">Vigencia hasta</span>
          <input
            v-model="form.ends_at"
            type="date"
            :class="inputClasses"
            :disabled="isSubmitting || scopeLocked"
          />
          <span v-if="fieldError('ends_at')" class="text-red-600 dark:text-red-400">
            {{ fieldError('ends_at') }}
          </span>
        </label>
      </div>

      <label v-if="isCreate" class="block space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">
          Copiar tabla de puntuación de
        </span>
        <select
          v-model="form.copy_rules_from_ranking_id"
          :class="inputClasses"
          :disabled="isSubmitting || isLoadingOptions"
        >
          <option value="">Ninguno</option>
          <option v-for="option in copyOptions" :key="option.id" :value="String(option.id)">
            {{ option.name }}
          </option>
        </select>
        <span v-if="fieldError('copy_rules_from_ranking_id')" class="text-red-600 dark:text-red-400">
          {{ fieldError('copy_rules_from_ranking_id') }}
        </span>
      </label>

      <div class="space-y-1">
        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
          <input
            v-model="form.active"
            type="checkbox"
            class="rounded border-slate-300 dark:border-slate-600"
            :disabled="isSubmitting || (isCreate && !canEnableActiveOnCreate)"
          />
          Activo
        </label>
        <p
          v-if="isCreate && !canEnableActiveOnCreate"
          class="text-xs text-slate-500 dark:text-slate-400"
        >
          {{ RANKING_ACTIVATE_NEEDS_RULES_NOTE }}
        </p>
        <p v-if="fieldError('active')" class="text-sm text-red-600 dark:text-red-400">
          {{ fieldError('active') }}
        </p>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="isSubmitting"
          @click="handleClose"
        >
          Cancelar
        </button>
        <button
          type="submit"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          :disabled="isSubmitting"
        >
          {{ submitLabel }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>
