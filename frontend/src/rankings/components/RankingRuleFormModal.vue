<script setup>
import { computed, reactive, ref, watch } from 'vue'

import BaseModal from '../../shared/components/BaseModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import RankingService from '../services/RankingService'
import {
  rankingRuleResultOptions,
  RANKING_RULE_SHARED_THIRD_NOTE,
} from '../utils/rankingRuleCatalog'

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
  rankingId: {
    type: [String, Number],
    default: null,
  },
  ranking: {
    type: Object,
    default: null,
  },
  rule: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const form = reactive({
  result_key: '',
  points: 0,
  active: true,
})

const usedKeys = computed(() =>
  (props.ranking?.rules ?? [])
    .map((rule) => rule.result_key)
    .filter((key) => Boolean(key) && key !== props.rule?.result_key),
)

const resultOptions = computed(() => {
  if (props.mode === 'edit') {
    return rankingRuleResultOptions
  }

  return rankingRuleResultOptions.filter((option) => !usedKeys.value.includes(option.key))
})

const modalTitle = computed(() =>
  props.mode === 'edit' ? 'Editar regla' : 'Nueva regla',
)

const modalDescription = computed(() =>
  props.mode === 'edit'
    ? 'Actualizá los puntos o el estado de esta regla.'
    : 'Elegí el resultado deportivo y los puntos que otorga.',
)

const submitLabel = computed(() => (props.mode === 'edit' ? 'Guardar cambios' : 'Guardar'))

const inputClasses =
  'w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100'

const fieldError = (field) => errors.value?.[field]?.[0] ?? ''

const resetState = () => {
  isSubmitting.value = false
  errors.value = {}
  errorMessage.value = ''
  form.result_key = ''
  form.points = 0
  form.active = true
}

const syncForm = () => {
  if (props.mode === 'edit' && props.rule) {
    form.result_key = props.rule.result_key || ''
    form.points = Number(props.rule.points) || 0
    form.active = props.rule.active !== false
    return
  }

  form.result_key = resultOptions.value[0]?.key || ''
  form.points = 0
  form.active = true
}

const handleClose = () => {
  if (isSubmitting.value) {
    return
  }

  emit('close')
}

const handleSubmit = async () => {
  if (!props.rankingId) {
    return
  }

  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    const result =
      props.mode === 'edit'
        ? await RankingService.updateRule(props.rankingId, props.rule.id, {
            points: Number(form.points),
            active: Boolean(form.active),
          })
        : await RankingService.createRule(props.rankingId, {
            result_key: form.result_key,
            points: Number(form.points),
            active: Boolean(form.active),
          })

    emit('saved', result)
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value = extractApiErrorMessage(
      error,
      props.mode === 'edit' ? 'No se pudo actualizar la regla.' : 'No se pudo crear la regla.',
    )
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
    syncForm()
  },
)
</script>

<template>
  <BaseModal
    :show="show"
    :title="modalTitle"
    :description="modalDescription"
    size="md"
    :prevent-close="isSubmitting"
    @close="handleClose"
  >
    <p v-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <form class="space-y-4" @submit.prevent="handleSubmit">
      <label class="block space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Resultado</span>
        <select
          v-if="mode === 'create'"
          v-model="form.result_key"
          :class="inputClasses"
          :disabled="isSubmitting || resultOptions.length === 0"
          required
        >
          <option v-if="resultOptions.length === 0" value="">
            No hay resultados disponibles
          </option>
          <option v-for="option in resultOptions" :key="option.key" :value="option.key">
            {{ option.label }}
          </option>
        </select>
        <p
          v-else
          class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
        >
          {{ rule?.name || 'Resultado' }}
        </p>
        <span v-if="fieldError('result_key')" class="text-red-600 dark:text-red-400">
          {{ fieldError('result_key') }}
        </span>
      </label>

      <label class="block space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Puntos</span>
        <input
          v-model.number="form.points"
          type="number"
          min="0"
          step="1"
          :class="inputClasses"
          :disabled="isSubmitting"
          required
        />
        <span v-if="fieldError('points')" class="text-red-600 dark:text-red-400">
          {{ fieldError('points') }}
        </span>
      </label>

      <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
        <input
          v-model="form.active"
          type="checkbox"
          class="rounded border-slate-300 dark:border-slate-600"
          :disabled="isSubmitting"
        />
        Activa
      </label>
      <p v-if="fieldError('active')" class="text-sm text-red-600 dark:text-red-400">
        {{ fieldError('active') }}
      </p>

      <p class="text-xs text-slate-500 dark:text-slate-400">
        {{ RANKING_RULE_SHARED_THIRD_NOTE }}
      </p>

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
          :disabled="isSubmitting || (mode === 'create' && resultOptions.length === 0)"
        >
          {{ submitLabel }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>
