<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

import PlayerForm from '../components/PlayerForm.vue'
import PlayerService from '../services/PlayerService'

const router = useRouter()
const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const handleSubmit = async (payload) => {
  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    await PlayerService.create(payload)
    await router.push('/players')
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value = error?.response?.data?.message || 'No se pudo crear el jugador.'
  } finally {
    isSubmitting.value = false
  }
}

const handleCancel = async () => {
  await router.push('/players')
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Nuevo jugador</h1>
    </div>

    <p v-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <PlayerForm
      :is-submitting="isSubmitting"
      :errors="errors"
      submit-label="Crear jugador"
      @submit="handleSubmit"
      @cancel="handleCancel"
    />
  </section>
</template>
