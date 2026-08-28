<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import { usePermissions } from '../../composables/usePermissions'
import CompetitionService from '../../competitions/services/CompetitionService'
import {
  isRegistrationsEditable,
  registrationsLockReason,
} from '../../competitions/utils/competitionStructure'
import { isDoublesCompetition } from '../../shared/constants/competitionType'
import BulkPlayerRegistrationModal from '../components/BulkPlayerRegistrationModal.vue'
import RegisterPairModal from '../components/RegisterPairModal.vue'
import RegistrationService from '../services/RegistrationService'
import { collectRegisteredMemberIds } from '../utils/registeredMemberIds'
import { resolveCompetitionCategorySlug } from '../../players/utils/playerRegistrationRowStatus'

const route = useRoute()
const { can } = usePermissions()
const canManageRegistrations = computed(() => can('registrations.manage'))
const competitionId = computed(() => route.params.id)
const competition = ref(null)

const registrations = ref([])
const isLoadingRegistrations = ref(false)
const registrationListError = ref('')

const showBulkRegistrationModal = ref(false)
const showRegisterPairModal = ref(false)
const registrationSuccessMessage = ref('')

const isDoubles = computed(() => isDoublesCompetition(competition.value))

const registeredMemberIds = computed(() => collectRegisteredMemberIds(registrations.value))

const competitionCategorySlug = computed(() => resolveCompetitionCategorySlug(competition.value))

const registrationsEditable = computed(() => isRegistrationsEditable(competition.value))

const registrationsLockMessage = computed(() => registrationsLockReason(competition.value))

const pageTitle = computed(() => {
  const name = competition.value?.name || `Competencia #${competitionId.value}`
  const suffix = isDoubles.value ? 'Parejas inscriptas' : 'Jugadores inscriptos'

  return `${suffix} - ${name}`
})

const emptyStateMessage = computed(() =>
  isDoubles.value
    ? 'Todavía no hay parejas registradas.'
    : 'Esta competencia todavía no tiene inscriptos.',
)

const openBulkRegistrationModal = () => {
  registrationSuccessMessage.value = ''
  showBulkRegistrationModal.value = true
}

const openRegisterPairModal = () => {
  registrationSuccessMessage.value = ''
  showRegisterPairModal.value = true
}

const handleBulkRegistrationSaved = async (result) => {
  registrationSuccessMessage.value =
    result?.message ||
    `Inscripción masiva procesada: ${result?.created ?? 0} inscriptos, ${result?.skipped ?? 0} omitidos.`
  showBulkRegistrationModal.value = false
  await loadRegistrations()
}

const handlePairRegistrationSaved = async (registration) => {
  registrationSuccessMessage.value = `Pareja "${registration?.display_name ?? ''}" registrada correctamente.`
  showRegisterPairModal.value = false
  await loadRegistrations()
}

const handleBulkRegistrationClose = () => {
  showBulkRegistrationModal.value = false
}

const handleRegisterPairClose = () => {
  showRegisterPairModal.value = false
}

const loadRegistrations = async () => {
  isLoadingRegistrations.value = true
  registrationListError.value = ''

  try {
    registrations.value = await RegistrationService.listByCompetition(competitionId.value)
  } catch (error) {
    registrationListError.value =
      error?.response?.data?.message || 'No se pudo cargar el listado de inscriptos.'
  } finally {
    isLoadingRegistrations.value = false
  }
}

const loadCompetition = async () => {
  try {
    competition.value = await CompetitionService.show(competitionId.value)
  } catch {
    competition.value = null
  }
}

onMounted(async () => {
  await Promise.all([loadRegistrations(), loadCompetition()])
})
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: competition?.tournament_id,
        tournamentName: competition?.tournament?.name,
        competitionId,
        competitionName: competition?.name,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ pageTitle }}
      </h1>

      <AppBackButton :fallback-to="`/competitions/${competitionId}`" />
    </div>

    <div v-if="registrationsEditable && canManageRegistrations" class="flex flex-wrap gap-2">
      <button
        v-if="isDoubles"
        type="button"
        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
        @click="openRegisterPairModal"
      >
        Registrar pareja
      </button>

      <button
        v-else
        type="button"
        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
        @click="openBulkRegistrationModal"
      >
        Inscripción
      </button>
    </div>

    <p
      v-if="!registrationsEditable && registrationsLockMessage"
      class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
    >
      {{ registrationsLockMessage }}
    </p>

    <p
      v-if="registrationSuccessMessage"
      class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
    >
      {{ registrationSuccessMessage }}
    </p>

    <p v-if="isLoadingRegistrations" class="text-sm text-slate-600 dark:text-slate-300">Cargando inscriptos...</p>
    <p v-else-if="registrationListError" class="text-sm text-red-600 dark:text-red-400">{{ registrationListError }}</p>

    <div
      v-else-if="registrations.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      {{ emptyStateMessage }}
    </div>

    <div
      v-else
      class="space-y-2 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
      <article
        v-for="registration in registrations"
        :key="registration.id"
        class="rounded border border-slate-200 p-3 text-sm dark:border-slate-700 dark:bg-slate-950/30"
      >
        <template v-if="isDoubles">
          <p class="font-medium text-slate-900 dark:text-slate-100">
            {{ registration.display_name }}
          </p>
        </template>

        <template v-else>
          <p class="font-medium text-slate-900 dark:text-slate-100">
            {{ registration.player?.first_name }} {{ registration.player?.last_name }}
          </p>
          <p class="text-slate-600 dark:text-slate-400">Apodo: {{ registration.player?.nickname || '-' }}</p>
        </template>
      </article>
    </div>

    <BulkPlayerRegistrationModal
      v-if="!isDoubles"
      :show="showBulkRegistrationModal"
      :competition-id="competitionId"
      :competition-category-slug="competitionCategorySlug"
      :registered-player-ids="[...registeredMemberIds]"
      @close="handleBulkRegistrationClose"
      @saved="handleBulkRegistrationSaved"
    />

    <RegisterPairModal
      v-if="isDoubles"
      :show="showRegisterPairModal"
      :competition-id="competitionId"
      :competition-category-slug="competitionCategorySlug"
      :registered-member-ids="registeredMemberIds"
      @close="handleRegisterPairClose"
      @saved="handlePairRegistrationSaved"
    />
  </section>
</template>
