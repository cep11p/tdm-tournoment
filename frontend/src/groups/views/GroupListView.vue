<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import CompetitionService from '../../competitions/services/CompetitionService'
import {
  isStructureEditable,
  structureLockReason,
} from '../../competitions/utils/competitionStructure'
import GroupService from '../services/GroupService'

const route = useRoute()
const competitionId = route.params.id

const competition = ref(null)
const groups = ref([])
const isLoading = ref(false)
const listError = ref('')

const isCreating = ref(false)
const createError = ref('')
const createSuccessMessage = ref('')
const form = reactive({
  name: '',
})

const competitionStructureEditable = computed(() => isStructureEditable(competition.value))

const competitionStructureLockReason = computed(() => structureLockReason(competition.value))

const loadCompetition = async () => {
  try {
    competition.value = await CompetitionService.show(competitionId)
  } catch {
    competition.value = null
  }
}

const loadGroups = async () => {
  isLoading.value = true
  listError.value = ''

  try {
    groups.value = await GroupService.listByCompetition(competitionId)
  } catch (error) {
    listError.value = error?.response?.data?.message || 'No se pudo cargar el listado de grupos.'
  } finally {
    isLoading.value = false
  }
}

const handleCreateGroup = async () => {
  if (!form.name.trim()) {
    createError.value = 'El nombre del grupo es obligatorio.'
    return
  }

  isCreating.value = true
  createError.value = ''
  createSuccessMessage.value = ''

  try {
    await GroupService.create(competitionId, {
      name: form.name.trim(),
    })

    form.name = ''
    createSuccessMessage.value = 'Grupo creado correctamente.'
    await loadGroups()
  } catch (error) {
    createError.value =
      error?.response?.data?.errors?.name?.[0] ||
      error?.response?.data?.errors?.competition?.[0] ||
      error?.response?.data?.message ||
      'No se pudo crear el grupo.'
  } finally {
    isCreating.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadCompetition(), loadGroups()])
})
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Grupos de la competencia</h1>
      <RouterLink
        :to="`/competitions/${competitionId}`"
        class="text-sm font-medium text-slate-700 dark:text-slate-200 hover:underline"
      >
        Volver a competencia
      </RouterLink>
    </div>

    <p
      v-if="!competitionStructureEditable && competitionStructureLockReason"
      class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
    >
      {{ competitionStructureLockReason }}
    </p>

    <form
      v-if="competitionStructureEditable"
      class="max-w-xl space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      @submit.prevent="handleCreateGroup"
    >
      <p class="font-medium text-slate-700 dark:text-slate-200">Crear grupo</p>

      <div>
        <label for="group-name" class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Nombre</label>
        <input
          id="group-name"
          v-model="form.name"
          type="text"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
        />
      </div>

      <p v-if="createError" class="text-red-600 dark:text-red-400">{{ createError }}</p>
      <p v-if="createSuccessMessage" class="text-emerald-700 dark:text-emerald-300">{{ createSuccessMessage }}</p>

      <button
        type="submit"
        class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
        :disabled="isCreating"
      >
        {{ isCreating ? 'Creando...' : 'Crear grupo' }}
      </button>
    </form>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-400">Cargando grupos...</p>
    <p v-else-if="listError" class="text-sm text-red-600 dark:text-red-400">{{ listError }}</p>

    <div
      v-else-if="groups.length === 0"
      class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-400"
    >
      Esta competencia todavía no tiene grupos.
    </div>

    <div v-else class="space-y-2 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
      <article
        v-for="group in groups"
        :key="group.id"
        class="flex items-center justify-between rounded border border-slate-200 p-3 text-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800/40 dark:hover:bg-slate-800/70"
      >
        <p class="font-medium text-slate-900 dark:text-slate-100">{{ group.name }}</p>

        <RouterLink
          :to="`/groups/${group.id}?competitionId=${competitionId}&groupName=${encodeURIComponent(group.name)}`"
          class="text-sm font-medium text-slate-700 dark:text-slate-200 hover:underline"
        >
          Ver detalle
        </RouterLink>
      </article>
    </div>
  </section>
</template>
