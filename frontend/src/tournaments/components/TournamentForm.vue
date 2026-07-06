<script setup>
import { reactive, watch } from 'vue'

import { buildTournamentPayload } from '../utils/buildTournamentPayload'

const props = defineProps({
  initialValues: {
    type: Object,
    default: () => ({
      name: '',
      location: '',
      start_date: '',
      end_date: '',
      status: 'draft',
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
  name: '',
  location: '',
  start_date: '',
  end_date: '',
  status: 'draft',
})

const syncForm = (values) => {
  form.name = values.name ?? ''
  form.location = values.location ?? ''
  form.start_date = values.start_date ?? ''
  form.end_date = values.end_date ?? ''
  form.status = values.status ?? 'draft'
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
  emit('submit', buildTournamentPayload(form))
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
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="tournament-name">
        Nombre
      </label>
      <input
        id="tournament-name"
        v-model="form.name"
        type="text"
        required
        :disabled="isSubmitting"
        :class="inputClasses"
      />
      <p v-if="fieldError('name')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('name') }}
      </p>
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="tournament-location">
        Ubicación
      </label>
      <input
        id="tournament-location"
        v-model="form.location"
        type="text"
        required
        :disabled="isSubmitting"
        :class="inputClasses"
      />
      <p v-if="fieldError('location')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('location') }}
      </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <div class="space-y-1">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="tournament-start-date">
          Fecha inicio
        </label>
        <input
          id="tournament-start-date"
          v-model="form.start_date"
          type="date"
          required
          :disabled="isSubmitting"
          :class="inputClasses"
        />
        <p v-if="fieldError('start_date')" class="text-xs text-red-600 dark:text-red-400">
          {{ fieldError('start_date') }}
        </p>
      </div>

      <div class="space-y-1">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="tournament-end-date">
          Fecha fin
        </label>
        <input
          id="tournament-end-date"
          v-model="form.end_date"
          type="date"
          :disabled="isSubmitting"
          :class="inputClasses"
        />
        <p v-if="fieldError('end_date')" class="text-xs text-red-600 dark:text-red-400">
          {{ fieldError('end_date') }}
        </p>
      </div>
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="tournament-status">
        Estado
      </label>
      <select
        id="tournament-status"
        v-model="form.status"
        :disabled="isSubmitting"
        :class="inputClasses"
      >
        <option value="draft">draft</option>
        <option value="in_progress">in_progress</option>
        <option value="finished">finished</option>
      </select>
      <p v-if="fieldError('status')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('status') }}
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
