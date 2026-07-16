<script setup>
import { onMounted, ref } from 'vue'

import CategoryService from '../../categories/services/CategoryService'
import ClubService from '../../clubs/services/ClubService'

const props = defineProps({
  searchQuery: {
    type: String,
    default: '',
  },
  categoryId: {
    type: [String, Number],
    default: '',
  },
  clubId: {
    type: [String, Number],
    default: '',
  },
  includeInactive: {
    type: Boolean,
    default: false,
  },
  showIncludeInactive: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  compact: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits([
  'update:searchQuery',
  'update:categoryId',
  'update:clubId',
  'update:includeInactive',
  'search',
])

const categories = ref([])
const clubs = ref([])
const isLoadingCatalogs = ref(false)

const loadCatalogs = async () => {
  isLoadingCatalogs.value = true

  try {
    const [categoryList, clubList] = await Promise.all([CategoryService.list(), ClubService.list()])
    categories.value = categoryList
    clubs.value = clubList
  } catch {
    categories.value = []
    clubs.value = []
  } finally {
    isLoadingCatalogs.value = false
  }
}

onMounted(loadCatalogs)
</script>

<template>
  <form
    :class="compact ? 'flex flex-wrap items-end gap-2' : 'flex flex-wrap items-end gap-3'"
    @submit.prevent="emit('search')"
  >
    <label :class="compact ? 'min-w-[12rem] flex-1' : 'min-w-[16rem] flex-1'">
      <span
        :class="[
          'mb-1 block font-medium text-slate-700 dark:text-slate-200',
          compact ? 'text-xs' : 'text-sm',
        ]"
      >
        Buscar jugador
      </span>
      <input
        :value="searchQuery"
        type="text"
        placeholder="Nombre, apellido o apodo"
        :class="[
          'w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100',
          compact ? 'text-sm' : 'text-sm',
        ]"
        :disabled="disabled || isLoadingCatalogs"
        @input="emit('update:searchQuery', $event.target.value)"
      />
    </label>

    <label class="min-w-[10rem]">
      <span
        :class="[
          'mb-1 block font-medium text-slate-700 dark:text-slate-200',
          compact ? 'text-xs' : 'text-sm',
        ]"
      >
        Categoría
      </span>
      <select
        :value="categoryId"
        :class="[
          'w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100',
          compact ? 'text-sm' : 'text-sm',
        ]"
        :disabled="disabled || isLoadingCatalogs"
        @change="emit('update:categoryId', $event.target.value)"
      >
        <option value="">Todas</option>
        <option v-for="category in categories" :key="category.id" :value="category.id">
          {{ category.name }}
        </option>
      </select>
    </label>

    <label class="min-w-[10rem]">
      <span
        :class="[
          'mb-1 block font-medium text-slate-700 dark:text-slate-200',
          compact ? 'text-xs' : 'text-sm',
        ]"
      >
        Club
      </span>
      <select
        :value="clubId"
        :class="[
          'w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100',
          compact ? 'text-sm' : 'text-sm',
        ]"
        :disabled="disabled || isLoadingCatalogs"
        @change="emit('update:clubId', $event.target.value)"
      >
        <option value="">Todos</option>
        <option v-for="club in clubs" :key="club.id" :value="club.id">
          {{ club.name }}
        </option>
      </select>
    </label>

    <button
      type="submit"
      class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
      :disabled="disabled || isLoadingCatalogs"
    >
      Buscar
    </button>

    <label
      v-if="showIncludeInactive"
      class="flex items-center gap-2 pb-2 text-sm text-slate-700 dark:text-slate-200"
    >
      <input
        :checked="includeInactive"
        type="checkbox"
        class="rounded border-slate-300 dark:border-slate-600"
        :disabled="disabled || isLoadingCatalogs"
        @change="emit('update:includeInactive', $event.target.checked)"
      />
      Incluir inactivos
    </label>
  </form>
</template>
