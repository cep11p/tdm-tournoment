import { useAuthStore } from '../stores/auth'

/**
 * @param {import('vue-router').Router} router
 * @param {import('pinia').Pinia} pinia
 */
export function setupAuthGuards(router, pinia) {
  const authStore = useAuthStore(pinia)

  router.beforeEach(async (to) => {
    if (to.name === 'forbidden') {
      return true
    }

    if (!authStore.isReady) {
      await waitForAuthReady(authStore)
    }

    const requiresAuth = to.meta.requiresAuth !== false

    if (requiresAuth && !authStore.isAuthenticated) {
      if (!authStore.isLoggingIn) {
        authStore.isLoggingIn = true
        await authStore.login()
      }

      return false
    }

    const requiredPermission = to.meta.permission

    if (requiredPermission && !authStore.hasPermission(requiredPermission)) {
      return {
        name: 'forbidden',
        query: {
          from: to.fullPath,
        },
      }
    }

    return true
  })
}

function waitForAuthReady(authStore) {
  if (authStore.isReady) {
    return Promise.resolve()
  }

  return new Promise((resolve) => {
    const intervalId = window.setInterval(() => {
      if (authStore.isReady) {
        window.clearInterval(intervalId)
        resolve()
      }
    }, 50)
  })
}
