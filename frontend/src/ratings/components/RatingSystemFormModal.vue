<script setup>
import { computed, reactive, ref, watch } from 'vue'

import BaseModal from '../../shared/components/BaseModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import RatingSystemService from '../services/RatingSystemService'
import { RATING_METHOD_DIFFERENCE_TABLE, ratingMethodLabel } from '../utils/ratingDisplay'

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
  ratingSystem: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const form = reactive({
  name: '',
  description: '',
  initial_rating: 1500,
  active: true,
})

const isCreate = computed(() => props.mode === 'create')
const methodLabel = ratingMethodLabel(RATING_METHOD_DIFFERENCE_TABLE)

const modalTitle = computed(() =>
  isCreate.value
    ? 'Nuevo sistema de rating'
    : props.ratingSystem?.name
      ? `Editar ${props.ratingSystem.name}`
      : 'Editar sistema de rating',
)

const modalDescription = computed(() =>
  isCreate.value
    ? 'Definí el nombre, el rating inicial y el estado del sistema.'
    : 'Actualizá los datos del sistema de rating.',
)

const submitLabel = computed(() => (isCreate.value ? 'Crear sistema' : 'Guardar cambios'))

const inputClasses =
  'w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100'

const fieldError = (field) => errors.value?.[field]?.[0] ?? ''

const resetState = () => {
  isSubmitting.value = false
  errors.value = {}
  errorMessage.value = ''
  form.name = ''
  form.description = ''
  form.initial_rating = 1500
  form.active = true
}

const syncForm = () => {
  if (isCreate.value || !props.ratingSystem) {
    form.name = ''
    form.description = ''
    form.initial_rating = 1500
    form.active = true
    return
  }

  form.name = props.ratingSystem.name ?? ''
  form.description = props.ratingSystem.description ?? ''
  form.initial_rating = props.ratingSystem.initial_rating ?? 0
  form.active = props.ratingSystem.active === true
}

const handleClose = () => {
  if (isSubmitting.value) {
    return
  }

  emit('close')
}

const buildPayload = () => ({
  name: form.name.trim(),
  description: form.description.trim() === '' ? null : form.description.trim(),
  initial_rating: form.initial_rating === '' ? '' : Number(form.initial_rating),
  // ponytail: un solo método; un selector cuando exista otro.
  method: RATING_METHOD_DIFFERENCE_TABLE,
  active: Boolean(form.active),
})

const handleSubmit = async () => {
  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    const payload = buildPayload()
    const result = isCreate.value
      ? await RatingSystemService.create(payload)
      : await RatingSystemService.update(props.ratingSystem.id, payload)

    emit('saved', result)
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value = extractApiErrorMessage(
      error,
      isCreate.value
        ? 'No se pudo crear el sistema de rating.'
        : 'No se pudo actualizar el sistema de rating.',
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
    size="lg"
    :prevent-close="isSubmitting"
    @close="handleClose"
  >
    <p v-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <form class="space-y-4" @submit.prevent="handleSubmit">
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
        <span class="font-medium text-slate-700 dark:text-slate-300">Descripción</span>
        <textarea
          v-model="form.description"
          rows="3"
          :class="inputClasses"
          :disabled="isSubmitting"
        />
        <span v-if="fieldError('description')" class="text-red-600 dark:text-red-400">
          {{ fieldError('description') }}
        </span>
      </label>

      <label class="block space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Rating inicial</span>
        <input
          v-model="form.initial_rating"
          type="number"
          min="0"
          step="1"
          :class="inputClasses"
          :disabled="isSubmitting"
          required
        />
        <span v-if="fieldError('initial_rating')" class="text-red-600 dark:text-red-400">
          {{ fieldError('initial_rating') }}
        </span>
      </label>

      <div class="space-y-1 text-sm">
        <p class="font-medium text-slate-700 dark:text-slate-300">Método de cálculo</p>
        <p class="text-slate-700 dark:text-slate-300">{{ methodLabel }}</p>
      </div>

      <div class="space-y-1">
        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
          <input
            v-model="form.active"
            type="checkbox"
            class="rounded border-slate-300 dark:border-slate-600"
            :disabled="isSubmitting"
          />
          Activo
        </label>
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
