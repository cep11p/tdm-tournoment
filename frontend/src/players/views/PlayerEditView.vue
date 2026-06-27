<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import PlayerForm from '../components/PlayerForm.vue'
import PlayerService from '../services/PlayerService'

const route = useRoute()
const router = useRouter()

const playerId = computed(() => route.params.id)
const player = ref(null)
const isLoading = ref(false)
const loadError = ref('')
const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const initialValues = computed(() => ({
  first_name: player.value?.first_name ?? '',
  last_name: player.value?.last_name ?? '',
  nickname: player.value?.nickname ?? '',
  active: player.value?.active ?? true,
}))

const loadPlayer = async () => {
  isLoading.value = true
  loadError.value = ''

  try {
    player.value = await PlayerService.show(playerId.value)
  } catch (error) {
    loadError.value = error?.response?.data?.message || 'No se pudo cargar el jugador.'
    player.value = null
  } finally {
    isLoading.value = false
  }
}

const handleSubmit = async (payload) => {
  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    await PlayerService.update(playerId.value, payload)
    await router.push('/players')
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value = error?.response?.data?.message || 'No se pudo actualizar el jugador.'
  } finally {
    isSubmitting.value = false
  }
}

const handleCancel = async () => {
  await router.push('/players')
}

onMounted(loadPlayer)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ player?.full_name || `Editar jugador #${playerId}` }}
      </h1>
      <AppBackButton fallback-to="/players" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando jugador...</p>
    <p v-else-if="loadError" class="text-sm text-red-600 dark:text-red-400">{{ loadError }}</p>

    <template v-else-if="player">
      <p v-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

      <PlayerForm
        :initial-values="initialValues"
        :is-submitting="isSubmitting"
        :errors="errors"
        submit-label="Guardar cambios"
        show-active-toggle
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </template>
  </section>
</template>
