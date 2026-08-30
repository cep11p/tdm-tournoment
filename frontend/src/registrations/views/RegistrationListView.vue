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
import {
  getParticipantKind,
  participantPlural,
} from '../../shared/constants/competitionType'
import BulkPlayerRegistrationModal from '../components/BulkPlayerRegistrationModal.vue'
import RegisterPairModal from '../components/RegisterPairModal.vue'
import RegisterTeamModal from '../components/RegisterTeamModal.vue'
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
const showRegisterTeamModal = ref(false)
const registrationSuccessMessage = ref('')

const participantKind = computed(() => getParticipantKind(competition.value))

const registeredMemberIds = computed(() => collectRegisteredMemberIds(registrations.value))

const competitionCategorySlug = computed(() => resolveCompetitionCategorySlug(competition.value))

const registrationsEditable = computed(() => isRegistrationsEditable(competition.value))

const registrationsLockMessage = computed(() => registrationsLockReason(competition.value))

const pageTitle = computed(() => {
  const name = competition.value?.name || `Competencia #${competitionId.value}`
  const suffix = `${participantPlural(competition.value)} inscriptos`

  return `${suffix.charAt(0).toUpperCase()}${suffix.slice(1)} - ${name}`
})

const emptyStateMessage = computed(() => {
  const label = participantPlural(competition.value)

  return `Todavía no hay ${label} registrados.`
})

const openBulkRegistrationModal = () => {
  registrationSuccessMessage.value = ''
  showBulkRegistrationModal.value = true
}

const openRegisterPairModal = () => {
  registrationSuccessMessage.value = ''
  showRegisterPairModal.value = true
}

const openRegisterTeamModal = () => {
  registrationSuccessMessage.value = ''
  showRegisterTeamModal.value = true
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

const handleTeamRegistrationSaved = async (registration) => {
  registrationSuccessMessage.value = `Equipo "${registration?.display_name ?? ''}" registrado correctamente.`
  showRegisterTeamModal.value = false
  await loadRegistrations()
}

const handleBulkRegistrationClose = () => {
  showBulkRegistrationModal.value = false
}

const handleRegisterPairClose = () => {
  showRegisterPairModal.value = false
}

const handleRegisterTeamClose = () => {
  showRegisterTeamModal.value = false
}

const memberCountLabel = (registration) => {
  const count = registration?.members?.length ?? 0

  return `${count} integrante${count === 1 ? '' : 's'}`
}

const memberNamesPreview = (registration) =>
  (registration?.members ?? [])
    .map((member) => `${member.first_name ?? ''} ${member.last_name ?? ''}`.trim())
    .filter(Boolean)
    .join(', ')

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
        v-if="participantKind === 'pair'"
        type="button"
        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
        @click="openRegisterPairModal"
      >
        Registrar pareja
      </button>

      <button
        v-else-if="participantKind === 'team'"
        type="button"
        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
        @click="openRegisterTeamModal"
      >
        Registrar equipo
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
        <template v-if="participantKind === 'player'">
          <p class="font-medium text-slate-900 dark:text-slate-100">
            {{ registration.player?.first_name }} {{ registration.player?.last_name }}
          </p>
          <p class="text-slate-600 dark:text-slate-400">Apodo: {{ registration.player?.nickname || '-' }}</p>
        </template>

        <template v-else-if="participantKind === 'team'">
          <p class="font-medium text-slate-900 dark:text-slate-100">
            {{ registration.display_name }}
          </p>
          <p class="text-slate-600 dark:text-slate-400">{{ memberCountLabel(registration) }}</p>
          <p v-if="memberNamesPreview(registration)" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
            {{ memberNamesPreview(registration) }}
          </p>
        </template>

        <template v-else>
          <p class="font-medium text-slate-900 dark:text-slate-100">
            {{ registration.display_name }}
          </p>
        </template>
      </article>
    </div>

    <BulkPlayerRegistrationModal
      v-if="participantKind === 'player'"
      :show="showBulkRegistrationModal"
      :competition-id="competitionId"
      :competition-category-slug="competitionCategorySlug"
      :registered-player-ids="[...registeredMemberIds]"
      @close="handleBulkRegistrationClose"
      @saved="handleBulkRegistrationSaved"
    />

    <RegisterPairModal
      v-if="participantKind === 'pair'"
      :show="showRegisterPairModal"
      :competition-id="competitionId"
      :competition-category-slug="competitionCategorySlug"
      :registered-member-ids="registeredMemberIds"
      @close="handleRegisterPairClose"
      @saved="handlePairRegistrationSaved"
    />

    <RegisterTeamModal
      v-if="participantKind === 'team'"
      :show="showRegisterTeamModal"
      :competition-id="competitionId"
      :competition-category-slug="competitionCategorySlug"
      :team-size="competition?.team_size ?? 4"
      :registered-member-ids="registeredMemberIds"
      @close="handleRegisterTeamClose"
      @saved="handleTeamRegistrationSaved"
    />
  </section>
</template>
