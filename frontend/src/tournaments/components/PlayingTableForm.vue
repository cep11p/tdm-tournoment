<script setup>
import { reactive, watch } from 'vue'

const props = defineProps({
  initialValues: {
    type: Object,
    default: () => ({
      number: 1,
      name: '',
      sort_order: 1,
      active: true,
    }),
  },
  isSubmitting: {
    type: Boolean,
    default: false,
  },
  errors: {
    type: Object,
    default: () => ({}),
  },
  submitLabel: {
    type: String,
    default: 'Guardar',
  },
  embedded: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['submit', 'cancel'])

const form = reactive({
  number: 1,
  name: '',
  sort_order: 1,
  active: true,
})

const syncForm = (values) => {
  form.number = values.number ?? 1
  form.name = values.name ?? ''
  form.sort_order = values.sort_order ?? values.number ?? 1
  form.active = values.active ?? true
}

watch(
  () => props.initialValues,
  (values) => {
    syncForm(values ?? {})
  },
  { immediate: true, deep: true },
)

const fieldError = (field) => props.errors?.[field]?.[0] ?? ''

const inputClasses =
  'w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100'

const handleSubmit = () => {
  const number = Number(form.number)
  const sortOrder = Number(form.sort_order)
  const name = String(form.name ?? '').trim()

  emit('submit', {
    number,
    name: name === '' ? null : name,
    sort_order: Number.isNaN(sortOrder) ? number : sortOrder,
    active: Boolean(form.active),
  })
}

const handleCancel = () => {
  emit('cancel')
}
</script>

<template>
  <form
    :class="[
      'space-y-4',
      embedded ? '' : 'max-w-xl rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900',
    ]"
    @submit.prevent="handleSubmit"
  >
    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="playing-table-number">
        Número de mesa
      </label>
      <input
        id="playing-table-number"
        v-model.number="form.number"
        type="number"
        min="1"
        max="999"
        required
        :disabled="isSubmitting"
        :class="inputClasses"
      />
      <p v-if="fieldError('number')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('number') }}
      </p>
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="playing-table-name">
        Nombre opcional
      </label>
      <input
        id="playing-table-name"
        v-model="form.name"
        type="text"
        maxlength="255"
        :disabled="isSubmitting"
        :class="inputClasses"
      />
      <p v-if="fieldError('name')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('name') }}
      </p>
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="playing-table-sort-order">
        Orden
      </label>
      <input
        id="playing-table-sort-order"
        v-model.number="form.sort_order"
        type="number"
        min="0"
        max="9999"
        required
        :disabled="isSubmitting"
        :class="inputClasses"
      />
      <p v-if="fieldError('sort_order')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('sort_order') }}
      </p>
    </div>

    <div class="space-y-1">
      <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
        <input
          v-model="form.active"
          type="checkbox"
          :disabled="isSubmitting"
          class="rounded border-slate-300 dark:border-slate-600"
        />
        Disponible para asignaciones
      </label>
      <p v-if="fieldError('active')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('active') }}
      </p>
    </div>

    <div class="flex items-center gap-3">
      <button
        type="submit"
        :disabled="isSubmitting"
        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
      >
        {{ isSubmitting ? 'Guardando...' : submitLabel }}
      </button>

      <button
        type="button"
        :disabled="isSubmitting"
        class="text-sm font-medium text-slate-700 hover:underline disabled:opacity-70 dark:text-slate-300"
        @click="handleCancel"
      >
        Cancelar
      </button>
    </div>
  </form>
</template>
