import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { setupAuthGuards } from './router/guards'
import { useTheme } from './composables/useTheme'
import { setupHttpInterceptors } from './services/httpInterceptors'
import { useAuthStore } from './stores/auth'
import './style.css'

const pinia = createPinia()
const app = createApp(App)

app.use(pinia)
app.use(router)

const authStore = useAuthStore(pinia)

setupHttpInterceptors(pinia, {
  clearSession: () => authStore.clearSession(),
  login: () => authStore.login(),
})

setupAuthGuards(router, pinia)

const { initializeTheme } = useTheme()
initializeTheme()

app.mount('#app')

void authStore.initialize()
