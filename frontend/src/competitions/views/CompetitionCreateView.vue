<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import CompetitionForm from '../components/CompetitionForm.vue'
import CompetitionService from '../services/CompetitionService'
import { DEFAULT_COMPETITION_FORM_VALUES } from '../utils/buildCompetitionPayload'

const route = useRoute()
const router = useRouter()

const tournamentId = route.params.id
const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const handleSubmit = async (payload) => {
  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    await CompetitionService.create(tournamentId, payload)
    await router.push(`/tournaments/${tournamentId}`)
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo crear la competencia.'
  } finally {
    isSubmitting.value = false
  }
}

const handleCancel = () => {
  router.push(`/tournaments/${tournamentId}`)
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Nueva competencia</h1>
    </div>

    <p class="text-sm text-slate-600 dark:text-slate-300">Formulario inicial para crear una competencia.</p>
    <p v-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <CompetitionForm
      mode="create"
      :initial-values="DEFAULT_COMPETITION_FORM_VALUES"
      :is-submitting="isSubmitting"
      :errors="errors"
      structure-editable
      @submit="handleSubmit"
      @cancel="handleCancel"
    />
  </section>
</template>
