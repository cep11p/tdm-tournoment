<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

import TournamentForm from '../components/TournamentForm.vue'
import TournamentService from '../services/TournamentService'

const router = useRouter()
const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const handleSubmit = async (payload) => {
  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    await TournamentService.create(payload)
    await router.push('/tournaments')
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo crear el torneo.'
  } finally {
    isSubmitting.value = false
  }
}

const handleCancel = async () => {
  await router.push('/tournaments')
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Nuevo torneo</h1>
    </div>

    <p class="text-sm text-slate-600 dark:text-slate-300">Formulario inicial para crear un torneo.</p>
    <p v-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <TournamentForm
      :is-submitting="isSubmitting"
      :errors="errors"
      @submit="handleSubmit"
      @cancel="handleCancel"
    />
  </section>
</template>
