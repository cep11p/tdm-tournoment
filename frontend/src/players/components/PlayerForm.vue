<script setup>
import { onMounted, reactive, ref, watch } from 'vue'

import CategoryService from '../../categories/services/CategoryService'
import ClubService from '../../clubs/services/ClubService'

const props = defineProps({
  initialValues: {
    type: Object,
    default: () => ({
      first_name: '',
      last_name: '',
      nickname: '',
      category_id: '',
      club_id: '',
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
  showActiveToggle: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['submit', 'cancel'])

const categories = ref([])
const clubs = ref([])

const form = reactive({
  first_name: '',
  last_name: '',
  nickname: '',
  category_id: '',
  club_id: '',
  active: true,
})

const syncForm = (values) => {
  form.first_name = values.first_name ?? ''
  form.last_name = values.last_name ?? ''
  form.nickname = values.nickname ?? ''
  form.category_id = values.category_id ?? values.category?.id ?? ''
  form.club_id = values.club_id ?? values.club?.id ?? ''
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

const loadCatalogs = async () => {
  try {
    const [categoryList, clubList] = await Promise.all([CategoryService.list(), ClubService.list()])
    categories.value = categoryList
    clubs.value = clubList
  } catch {
    categories.value = []
    clubs.value = []
  }
}

const handleSubmit = () => {
  const payload = {
    first_name: form.first_name.trim(),
    last_name: form.last_name.trim(),
    nickname: form.nickname.trim() || null,
    category_id: form.category_id === '' ? null : Number(form.category_id),
    club_id: form.club_id === '' ? null : Number(form.club_id),
  }

  if (props.showActiveToggle) {
    payload.active = form.active
  }

  emit('submit', payload)
}

const handleCancel = () => {
  emit('cancel')
}

onMounted(loadCatalogs)
</script>

<template>
  <form
    class="max-w-xl space-y-4 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    @submit.prevent="handleSubmit"
  >
    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="first_name">
        Nombre
      </label>
      <input
        id="first_name"
        v-model="form.first_name"
        type="text"
        required
        :disabled="isSubmitting"
        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
      />
      <p v-if="fieldError('first_name')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('first_name') }}
      </p>
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="last_name">
        Apellido
      </label>
      <input
        id="last_name"
        v-model="form.last_name"
        type="text"
        required
        :disabled="isSubmitting"
        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
      />
      <p v-if="fieldError('last_name')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('last_name') }}
      </p>
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="nickname">
        Apodo
      </label>
      <input
        id="nickname"
        v-model="form.nickname"
        type="text"
        :disabled="isSubmitting"
        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
      />
      <p class="text-xs text-slate-500 dark:text-slate-400">Opcional. Debe ser único si se completa.</p>
      <p v-if="fieldError('nickname')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('nickname') }}
      </p>
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="category_id">
        Categoría principal
      </label>
      <select
        id="category_id"
        v-model="form.category_id"
        :disabled="isSubmitting"
        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
      >
        <option value="">Sin categoría</option>
        <option v-for="category in categories" :key="category.id" :value="category.id">
          {{ category.name }}
        </option>
      </select>
      <p v-if="fieldError('category_id')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('category_id') }}
      </p>
    </div>

    <div class="space-y-1">
      <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="club_id">
        Club
      </label>
      <select
        id="club_id"
        v-model="form.club_id"
        :disabled="isSubmitting"
        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:opacity-70 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
      >
        <option value="">Sin club</option>
        <option v-for="club in clubs" :key="club.id" :value="club.id">
          {{ club.name }}
        </option>
      </select>
      <p v-if="fieldError('club_id')" class="text-xs text-red-600 dark:text-red-400">
        {{ fieldError('club_id') }}
      </p>
    </div>

    <div v-if="showActiveToggle" class="space-y-1">
      <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
        <input
          v-model="form.active"
          type="checkbox"
          :disabled="isSubmitting"
          class="rounded border-slate-300 dark:border-slate-600"
        />
        Jugador activo
      </label>
      <p class="text-xs text-slate-500 dark:text-slate-400">
        Un jugador inactivo no aparecerá en nuevas inscripciones.
      </p>
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
