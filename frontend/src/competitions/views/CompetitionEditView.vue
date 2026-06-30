<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import { FORMAT_OPTIONS } from '../constants/competitionFormats'
import CompetitionService from '../services/CompetitionService'
import {
  isStructureEditable,
  structureLockReason,
} from '../utils/competitionStructure'

const route = useRoute()
const router = useRouter()

const competitionId = computed(() => route.params.id)
const competition = ref(null)
const isLoading = ref(false)
const loadError = ref('')
const isSubmitting = ref(false)
const errorMessage = ref('')

const form = reactive({
  name: '',
  category: '',
  type: 'singles',
  format: 'groups_knockout',
  points_per_set: 11,
  qualified_per_group: 2,
  group_stage_best_of: 5,
  knockout_stage_best_of: 5,
  semifinal_best_of: 7,
  final_best_of: 7,
})

const bestOfOptions = [1, 3, 5, 7]

const structureEditable = computed(() => isStructureEditable(competition.value))

const lockReason = computed(
  () =>
    structureLockReason(competition.value) ||
    'La estructura deportiva de esta competencia ya no puede modificarse.',
)

const showGroupStageFields = computed(
  () => form.format === 'groups_knockout' || form.format === 'manual',
)

const structuralFieldClasses =
  'w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500 disabled:cursor-not-allowed disabled:opacity-60'

const populateForm = (data) => {
  form.name = data.name ?? ''
  form.category = data.category ?? ''
  form.type = data.type ?? 'singles'
  form.format = data.format ?? 'groups_knockout'
  form.points_per_set = data.points_per_set ?? 11
  form.qualified_per_group = data.qualified_per_group ?? 2
  form.group_stage_best_of = data.group_stage_best_of ?? 5
  form.knockout_stage_best_of = data.knockout_stage_best_of ?? 5
  form.semifinal_best_of = data.semifinal_best_of ?? 7
  form.final_best_of = data.final_best_of ?? 7
}

const loadCompetition = async () => {
  isLoading.value = true
  loadError.value = ''

  try {
    const data = await CompetitionService.show(competitionId.value)
    competition.value = data
    populateForm(data)
  } catch (error) {
    loadError.value = extractApiErrorMessage(error, 'No se pudo cargar la competencia.')
    competition.value = null
  } finally {
    isLoading.value = false
  }
}

const buildFullPayload = () => ({
  name: form.name,
  category: form.category,
  type: form.type,
  format: form.format,
  points_per_set: Number(form.points_per_set),
  qualified_per_group: showGroupStageFields.value ? Number(form.qualified_per_group) : 2,
  group_stage_best_of: showGroupStageFields.value ? Number(form.group_stage_best_of) : 5,
  knockout_stage_best_of: Number(form.knockout_stage_best_of),
  semifinal_best_of: Number(form.semifinal_best_of),
  final_best_of: Number(form.final_best_of),
})

const submit = async () => {
  isSubmitting.value = true
  errorMessage.value = ''

  const payload = structureEditable.value
    ? buildFullPayload()
    : { name: form.name, category: form.category }

  try {
    await CompetitionService.update(competitionId.value, payload)
    await router.push(`/competitions/${competitionId.value}`)
  } catch (error) {
    errorMessage.value = extractApiErrorMessage(error, 'No se pudo actualizar la competencia.')
  } finally {
    isSubmitting.value = false
  }
}

onMounted(loadCompetition)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ competition?.name ? `Editar ${competition.name}` : 'Editar competencia' }}
      </h1>
    </div>

    <p class="text-sm text-slate-600 dark:text-slate-300">Modificá los datos de la competencia.</p>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando competencia...</p>
    <p v-else-if="loadError" class="text-sm text-red-600 dark:text-red-400">{{ loadError }}</p>

    <template v-else-if="competition">
      <p v-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

      <p
        v-if="!structureEditable"
        class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
      >
        {{ lockReason }}
      </p>

      <form
        class="max-w-xl space-y-4 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
        @submit.prevent="submit"
      >
        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="name">Nombre</label>
          <input
            id="name"
            v-model="form.name"
            type="text"
            required
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
          />
        </div>

        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="category">
            Categoría
          </label>
          <input
            id="category"
            v-model="form.category"
            type="text"
            required
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
          />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="space-y-1">
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="type">Tipo</label>
            <select
              id="type"
              v-model="form.type"
              required
              :disabled="!structureEditable"
              :class="structuralFieldClasses"
            >
              <option value="singles">singles</option>
            </select>
          </div>

          <div class="space-y-1">
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="format">Formato</label>
            <select
              id="format"
              v-model="form.format"
              required
              :disabled="!structureEditable"
              :class="structuralFieldClasses"
            >
              <option v-for="option in FORMAT_OPTIONS" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </div>
        </div>

        <p
          v-if="!showGroupStageFields"
          class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100"
        >
          En eliminación directa, todos los inscriptos pasan directamente a la llave. No se generan grupos ni
          standings.
        </p>

        <div class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="points_per_set">
            Puntos por set
          </label>
          <input
            id="points_per_set"
            v-model.number="form.points_per_set"
            type="number"
            min="1"
            required
            :disabled="!structureEditable"
            :class="structuralFieldClasses"
          />
        </div>

        <div v-if="showGroupStageFields" class="space-y-1">
          <label class="block text-sm font-medium text-slate-700 dark:text-slate-200" for="qualified_per_group">
            Clasificados por grupo
          </label>
          <input
            id="qualified_per_group"
            v-model.number="form.qualified_per_group"
            type="number"
            min="1"
            required
            :disabled="!structureEditable"
            :class="structuralFieldClasses"
          />
        </div>

        <fieldset class="space-y-3 rounded-md border border-slate-200 p-3 dark:border-slate-700">
          <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-200">Formato de partidos</legend>

          <div class="grid gap-4 sm:grid-cols-2">
            <div v-if="showGroupStageFields" class="space-y-1">
              <label class="block text-sm text-slate-600 dark:text-slate-300" for="group_stage_best_of">
                Fase de grupos: mejor de
              </label>
              <select
                id="group_stage_best_of"
                v-model.number="form.group_stage_best_of"
                required
                :disabled="!structureEditable"
                :class="structuralFieldClasses"
              >
                <option v-for="option in bestOfOptions" :key="`group-${option}`" :value="option">
                  {{ option }}
                </option>
              </select>
            </div>

            <div class="space-y-1">
              <label class="block text-sm text-slate-600 dark:text-slate-300" for="knockout_stage_best_of">
                Eliminatorias tempranas: mejor de
              </label>
              <select
                id="knockout_stage_best_of"
                v-model.number="form.knockout_stage_best_of"
                required
                :disabled="!structureEditable"
                :class="structuralFieldClasses"
              >
                <option v-for="option in bestOfOptions" :key="`knockout-${option}`" :value="option">
                  {{ option }}
                </option>
              </select>
            </div>

            <div class="space-y-1">
              <label class="block text-sm text-slate-600 dark:text-slate-300" for="semifinal_best_of">
                Semifinal: mejor de
              </label>
              <select
                id="semifinal_best_of"
                v-model.number="form.semifinal_best_of"
                required
                :disabled="!structureEditable"
                :class="structuralFieldClasses"
              >
                <option v-for="option in bestOfOptions" :key="`semifinal-${option}`" :value="option">
                  {{ option }}
                </option>
              </select>
            </div>

            <div class="space-y-1">
              <label class="block text-sm text-slate-600 dark:text-slate-300" for="final_best_of">
                Final: mejor de
              </label>
              <select
                id="final_best_of"
                v-model.number="form.final_best_of"
                required
                :disabled="!structureEditable"
                :class="structuralFieldClasses"
              >
                <option v-for="option in bestOfOptions" :key="`final-${option}`" :value="option">
                  {{ option }}
                </option>
              </select>
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

          <RouterLink
            :to="`/competitions/${competitionId}`"
            class="text-sm font-medium text-slate-700 dark:text-slate-200 hover:underline"
          >
            Cancelar
          </RouterLink>
        </div>
      </form>
    </template>
  </section>
</template>
