<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'

import CategoryService from '../../categories/services/CategoryService'
import TeamTieFormatService from '../../team-ties/services/TeamTieFormatService'
import { FORMAT_OPTIONS } from '../constants/competitionFormats'
import { getCompetitionTypeLabel } from '../../shared/constants/competitionType'
import {
  buildCompetitionPayload,
  DEFAULT_COMPETITION_FORM_VALUES,
} from '../utils/buildCompetitionPayload'
import { THIRD_PLACE_MODE_OPTIONS } from '../constants/thirdPlaceMode'
import CompetitionContextHint from './CompetitionContextHint.vue'

const props = defineProps({
  initialValues: {
    type: Object,
    default: () => ({ ...DEFAULT_COMPETITION_FORM_VALUES }),
  },
  isSubmitting: {
    type: Boolean,
    default: false,
  },
  errors: {
    type: Object,
    default: () => ({}),
  },
  mode: {
    type: String,
    default: 'create',
    validator: (value) => ['create', 'edit'].includes(value),
  },
  structureEditable: {
    type: Boolean,
    default: true,
  },
  structureLockReason: {
    type: String,
    default: '',
  },
  embedded: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['submit', 'cancel'])

const categories = ref([])
const teamTieFormats = ref([])

const form = reactive({
  name: '',
  category_id: '',
  type: 'singles',
  team_size: 4,
  team_tie_format_id: '',  format: 'groups_knockout',
  points_per_set: 11,
  qualified_per_group: 2,
  group_stage_best_of: 5,
  knockout_stage_best_of: 5,
  semifinal_best_of: 7,
  final_best_of: 7,
  third_place_mode: 'shared',
})

const bestOfOptions = [1, 3, 5, 7]

const syncForm = (values) => {
  form.name = values.name ?? ''
  form.category_id = values.category_id ?? ''
  form.type = values.type ?? 'singles'
  form.team_size = values.team_size ?? 4
  form.team_tie_format_id = values.team_tie_format_id ?? ''
  form.format = values.format ?? 'groups_knockout'
  form.points_per_set = values.points_per_set ?? 11
  form.qualified_per_group = values.qualified_per_group ?? 2
  form.group_stage_best_of = values.group_stage_best_of ?? 5
  form.knockout_stage_best_of = values.knockout_stage_best_of ?? 5
  form.semifinal_best_of = values.semifinal_best_of ?? 7
  form.final_best_of = values.final_best_of ?? 7
  form.third_place_mode = values.third_place_mode ?? 'shared'
}

watch(
  () => props.initialValues,
  (values) => {
    syncForm(values ?? {})
  },
  { immediate: true, deep: true },
)

const showGroupStageFields = computed(
  () => form.format === 'groups_knockout' || form.format === 'manual',
)

const showTeamSizeField = computed(() => form.type === 'team')

const showTeamTieFormatField = computed(() => form.type === 'team')

const selectedTeamTieFormat = computed(() =>
  teamTieFormats.value.find(
    (format) => Number(format.id) === Number(form.team_tie_format_id),
  ),
)

const teamTieFormatSummary = computed(() => {
  const format = selectedTeamTieFormat.value

  if (!format) {
    return ''
  }

  const matchCount = format.slots?.length ?? 0

  return `${format.name} · ${matchCount} partidos · gana con ${format.victories_required}`
})

const fieldsDisabled = computed(
  () => props.mode === 'edit' && !props.structureEditable,
)

const fieldError = (field) => props.errors?.[field]?.[0] ?? ''

const inputClasses =
  'w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500'

const structuralFieldClasses =
  'w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:cursor-not-allowed disabled:opacity-60'

const structuralInputClass = computed(() =>
  props.mode === 'edit' ? structuralFieldClasses : inputClasses,
)

const handleSubmit = () => {
  emit(
    'submit',
    buildCompetitionPayload(form, {
      structureEditable: props.mode === 'create' ? true : props.structureEditable,
    }),
  )
}

const handleCancel = () => {
  emit('cancel')
}

const loadTeamTieFormats = async () => {
  try {
    teamTieFormats.value = await TeamTieFormatService.list()
  } catch {
    teamTieFormats.value = []
  }
}

const loadCategories = async () => {
  try {
    categories.value = await CategoryService.list()
  } catch {
    categories.value = []
  }
}

onMounted(async () => {
  await Promise.all([loadCategories(), loadTeamTieFormats()])
})
</script>

<template>
  <div :class="embedded ? 'space-y-4' : 'max-w-xl space-y-4'">
    <CompetitionContextHint
      v-if="mode === 'edit' && !structureEditable && structureLockReason"
      :message="structureLockReason"
      variant="warning"
      use-lock-icon
    />

    <form
      :class="[
        'space-y-4',
        embedded ? '' : 'rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900',
      ]"
      @submit.prevent="handleSubmit"
    >
      <div class="space-y-1">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="competition-name">
          Nombre
        </label>
        <input
          id="competition-name"
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
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="competition-category">
          Categoría
        </label>
        <select
          id="competition-category"
          v-model="form.category_id"
          required
          :disabled="isSubmitting"
          :class="inputClasses"
        >
          <option value="" disabled>Seleccionar categoría</option>
          <option v-for="category in categories" :key="category.id" :value="category.id">
            {{ category.name }}
          </option>
        </select>
        <p v-if="fieldError('category_id')" class="text-xs text-red-600 dark:text-red-400">
          {{ fieldError('category_id') }}
        </p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="competition-type">
            Tipo
          </label>
          <select
            id="competition-type"
            v-model="form.type"
            required
            :disabled="fieldsDisabled || isSubmitting"
            :class="structuralInputClass"
          >
            <option value="singles">{{ getCompetitionTypeLabel('singles') }}</option>
            <option value="doubles">{{ getCompetitionTypeLabel('doubles') }}</option>
            <option value="team">{{ getCompetitionTypeLabel('team') }}</option>
          </select>
          <p v-if="fieldError('type')" class="text-xs text-red-600 dark:text-red-400">
            {{ fieldError('type') }}
          </p>
        </div>

        <div v-if="showTeamSizeField" class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="competition-team-size">
            Tamaño del equipo
          </label>
          <input
            id="competition-team-size"
            v-model.number="form.team_size"
            type="number"
            min="2"
            max="20"
            required
            :disabled="fieldsDisabled || isSubmitting"
            :class="structuralInputClass"
          />
          <p class="text-xs text-slate-500 dark:text-slate-400">
            Cantidad de jugadores que debe tener cada equipo.
          </p>
          <p v-if="fieldError('team_size')" class="text-xs text-red-600 dark:text-red-400">
            {{ fieldError('team_size') }}
          </p>
        </div>

        <div v-if="showTeamTieFormatField" class="space-y-1 sm:col-span-2">
          <label
            class="block text-sm font-medium text-slate-700 dark:text-slate-200"
            for="competition-team-tie-format"
          >
            Formato de enfrentamiento
          </label>
          <select
            id="competition-team-tie-format"
            v-model="form.team_tie_format_id"
            required
            :disabled="fieldsDisabled || isSubmitting"
            :class="structuralInputClass"
          >
            <option value="" disabled>Seleccionar formato</option>
            <option v-for="format in teamTieFormats" :key="format.id" :value="format.id">
              {{ format.name }} · {{ format.slots?.length ?? 0 }} partidos · gana con
              {{ format.victories_required }}
            </option>
          </select>
          <p v-if="teamTieFormatSummary" class="text-xs text-slate-500 dark:text-slate-400">
            {{ teamTieFormatSummary }}
          </p>
          <p v-if="fieldError('team_tie_format_id')" class="text-xs text-red-600 dark:text-red-400">
            {{ fieldError('team_tie_format_id') }}
          </p>
        </div>

        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="competition-format">
            Formato
          </label>
          <select
            id="competition-format"
            v-model="form.format"
            required
            :disabled="fieldsDisabled || isSubmitting"
            :class="structuralInputClass"
          >
            <option v-for="option in FORMAT_OPTIONS" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
          <p v-if="fieldError('format')" class="text-xs text-red-600 dark:text-red-400">
            {{ fieldError('format') }}
          </p>
        </div>
      </div>

      <p
        v-if="!showGroupStageFields"
        class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100"
      >
        En eliminación directa, todos los inscriptos pasan directamente a la llave. No se generan grupos ni
        tablas de posiciones.
      </p>

      <div class="space-y-1">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="competition-points-per-set">
          Puntos por set
        </label>
        <input
          id="competition-points-per-set"
          v-model.number="form.points_per_set"
          type="number"
          min="1"
          required
          :disabled="fieldsDisabled || isSubmitting"
          :class="structuralInputClass"
        />
        <p v-if="fieldError('points_per_set')" class="text-xs text-red-600 dark:text-red-400">
          {{ fieldError('points_per_set') }}
        </p>
      </div>

      <div v-if="showGroupStageFields" class="space-y-1">
        <label
          class="block text-sm font-medium text-slate-700 dark:text-slate-200"
          for="competition-qualified-per-group"
        >
          Clasificados por grupo
        </label>
        <input
          id="competition-qualified-per-group"
          v-model.number="form.qualified_per_group"
          type="number"
          min="1"
          required
          :disabled="fieldsDisabled || isSubmitting"
          :class="structuralInputClass"
        />
        <p v-if="fieldError('qualified_per_group')" class="text-xs text-red-600 dark:text-red-400">
          {{ fieldError('qualified_per_group') }}
        </p>
      </div>

      <fieldset
        class="space-y-3 rounded-md border border-slate-200 p-3 dark:border-slate-700"
        :disabled="fieldsDisabled || isSubmitting"
      >
        <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-200">
          Tercer puesto
        </legend>

        <label
          v-for="option in THIRD_PLACE_MODE_OPTIONS"
          :key="option.value"
          class="flex cursor-pointer gap-3 rounded-md border border-slate-200 p-3 dark:border-slate-700"
          :class="
            fieldsDisabled || isSubmitting
              ? 'cursor-not-allowed opacity-60'
              : 'hover:border-slate-300 dark:hover:border-slate-600'
          "
        >
          <input
            v-model="form.third_place_mode"
            type="radio"
            class="mt-1"
            :value="option.value"
            :disabled="fieldsDisabled || isSubmitting"
          />
          <span>
            <span class="block text-sm font-medium text-slate-900 dark:text-slate-100">
              {{ option.label }}
            </span>
            <span class="mt-1 block text-sm text-slate-600 dark:text-slate-300">
              {{ option.description }}
            </span>
          </span>
        </label>

        <p v-if="fieldError('third_place_mode')" class="text-xs text-red-600 dark:text-red-400">
          {{ fieldError('third_place_mode') }}
        </p>
      </fieldset>

      <fieldset class="space-y-3 rounded-md border border-slate-200 p-3 dark:border-slate-700">
        <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-200">Formato de partidos</legend>

        <div class="grid gap-4 sm:grid-cols-2">
          <div v-if="showGroupStageFields" class="space-y-1">
            <label class="block text-sm text-slate-600 dark:text-slate-300" for="competition-group-stage-best-of">
              Fase de grupos: mejor de
            </label>
            <select
              id="competition-group-stage-best-of"
              v-model.number="form.group_stage_best_of"
              required
              :disabled="fieldsDisabled || isSubmitting"
              :class="structuralInputClass"
            >
              <option v-for="option in bestOfOptions" :key="`group-${option}`" :value="option">
                {{ option }}
              </option>
            </select>
            <p v-if="fieldError('group_stage_best_of')" class="text-xs text-red-600 dark:text-red-400">
              {{ fieldError('group_stage_best_of') }}
            </p>
          </div>

          <div class="space-y-1">
            <label class="block text-sm text-slate-600 dark:text-slate-300" for="competition-knockout-stage-best-of">
              Eliminatorias tempranas: mejor de
            </label>
            <select
              id="competition-knockout-stage-best-of"
              v-model.number="form.knockout_stage_best_of"
              required
              :disabled="fieldsDisabled || isSubmitting"
              :class="structuralInputClass"
            >
              <option v-for="option in bestOfOptions" :key="`knockout-${option}`" :value="option">
                {{ option }}
              </option>
            </select>
            <p v-if="fieldError('knockout_stage_best_of')" class="text-xs text-red-600 dark:text-red-400">
              {{ fieldError('knockout_stage_best_of') }}
            </p>
          </div>

          <div class="space-y-1">
            <label class="block text-sm text-slate-600 dark:text-slate-300" for="competition-semifinal-best-of">
              Semifinal: mejor de
            </label>
            <select
              id="competition-semifinal-best-of"
              v-model.number="form.semifinal_best_of"
              required
              :disabled="fieldsDisabled || isSubmitting"
              :class="structuralInputClass"
            >
              <option v-for="option in bestOfOptions" :key="`semifinal-${option}`" :value="option">
                {{ option }}
              </option>
            </select>
            <p v-if="fieldError('semifinal_best_of')" class="text-xs text-red-600 dark:text-red-400">
              {{ fieldError('semifinal_best_of') }}
            </p>
          </div>

          <div class="space-y-1">
            <label class="block text-sm text-slate-600 dark:text-slate-300" for="competition-final-best-of">
              Final: mejor de
            </label>
            <select
              id="competition-final-best-of"
              v-model.number="form.final_best_of"
              required
              :disabled="fieldsDisabled || isSubmitting"
              :class="structuralInputClass"
            >
              <option v-for="option in bestOfOptions" :key="`final-${option}`" :value="option">
                {{ option }}
              </option>
            </select>
            <p v-if="fieldError('final_best_of')" class="text-xs text-red-600 dark:text-red-400">
              {{ fieldError('final_best_of') }}
            </p>
          </div>
        </div>
      </fieldset>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="isSubmitting"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
        >
          {{ isSubmitting ? 'Guardando...' : 'Guardar' }}
        </button>

        <button
          type="button"
          :disabled="isSubmitting"
          class="text-sm font-medium text-slate-700 hover:underline disabled:opacity-70 dark:text-slate-200"
          @click="handleCancel"
        >
          Cancelar
        </button>
      </div>
    </form>
  </div>
</template>
