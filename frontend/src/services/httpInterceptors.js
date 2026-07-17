import { getToken } from '../auth/keycloak'
import { useAuthStore } from '../stores/auth'
import httpClient from './httpClient'

/** @type {Promise<boolean> | null} */
let refreshPromise = null

let handling401 = false

const FORBIDDEN_MESSAGE = 'No tenés permiso para realizar esta acción.'

/**
 * @param {import('pinia').Pinia} pinia
 * @param {{ login: () => Promise<void>, clearSession: () => void }} authActions
 */
export function setupHttpInterceptors(pinia, authActions) {
  const getAuthStore = () => useAuthStore(pinia)

  httpClient.interceptors.request.use(async (config) => {
    const authStore = getAuthStore()

    if (!authStore.isAuthenticated) {
      return config
    }

    if (!refreshPromise) {
      refreshPromise = authStore.refreshToken(30).finally(() => {
        refreshPromise = null
      })
    }

    await refreshPromise

    const token = getToken()

    if (token) {
      config.headers = config.headers ?? {}
      config.headers.Authorization = `Bearer ${token}`
    }

    return config
  })

  httpClient.interceptors.response.use(
    (response) => response,
    async (error) => {
      const status = error?.response?.status

      if (status === 403) {
        const forbiddenError = new Error(FORBIDDEN_MESSAGE)
        forbiddenError.response = error.response
        forbiddenError.isForbidden = true
        forbiddenError.code = 'forbidden'
        return Promise.reject(forbiddenError)
      }

      if (status === 401) {
        if (error.config?.skipAuthRedirect) {
          return Promise.reject(error)
        }

        if (handling401) {
          return Promise.reject(error)
        }

        handling401 = true

        try {
          authActions.clearSession()
          await authActions.login()
        } finally {
          handling401 = false
        }

        const unauthenticatedError = new Error('Tu sesión expiró. Volvé a iniciar sesión.')
        unauthenticatedError.response = error.response
        unauthenticatedError.isUnauthenticated = true
        unauthenticatedError.code = 'unauthenticated'
        return Promise.reject(unauthenticatedError)
      }

      return Promise.reject(error)
    },
  )
}

export { FORBIDDEN_MESSAGE }
