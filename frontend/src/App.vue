<script setup>
import { computed } from 'vue'
import { RouterView } from 'vue-router'

import AppLayout from './layouts/AppLayout.vue'
import { useAuthStore } from './stores/auth'
import AuthLoadingView from './views/AuthLoadingView.vue'

const authStore = useAuthStore()

const canShowApp = computed(() => authStore.canShowApp)
</script>

<template>
  <AuthLoadingView v-if="!canShowApp" />
  <AppLayout v-else>
    <RouterView />
  </AppLayout>
</template>
