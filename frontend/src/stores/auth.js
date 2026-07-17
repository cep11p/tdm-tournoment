import { defineStore } from 'pinia'

import {
  initKeycloak,
  isAuthenticated as keycloakIsAuthenticated,
  login as keycloakLogin,
  logout as keycloakLogout,
  updateToken as keycloakUpdateToken,
} from '../auth/keycloak'
import { KeycloakConfigError } from '../config/runtime'
import AuthService from '../services/AuthService'

const normalizeList = (items) => [...new Set(items ?? [])].sort()

const emptyUser = () => ({
  id: null,
  keycloak_id: null,
  name: null,
  email: null,
})

export const useAuthStore = defineStore('auth', {
  state: () => ({
    isReady: false,
    isAuthenticated: false,
    isLoadingProfile: false,
    user: emptyUser(),
    roles: [],
    permissions: [],
    error: null,
    configError: null,
    isLoggingIn: false,
  }),

  getters: {
    displayName: (state) => state.user?.name || state.user?.email || 'Usuario',
    rolesLabel: (state) => (state.roles.length > 0 ? state.roles.join(', ') : null),
    hasProfile: (state) => Boolean(state.user?.id),
    canShowApp: (state) =>
      state.isReady &&
      state.isAuthenticated &&
      Boolean(state.user?.id) &&
      !state.isLoadingProfile &&
      !state.configError &&
      !state.error,
  },

  actions: {
    hasPermission(permission) {
      if (!permission || typeof permission !== 'string') {
        return false
      }

      return this.permissions.includes(permission)
    },

    hasAnyPermission(permissions) {
      if (!Array.isArray(permissions) || permissions.length === 0) {
        return false
      }

      return permissions.some((permission) => this.hasPermission(permission))
    },

    hasRole(role) {
      if (!role || typeof role !== 'string') {
        return false
      }

      return this.roles.includes(role)
    },

    clearSession() {
      this.isAuthenticated = false
      this.user = emptyUser()
      this.roles = []
      this.permissions = []
      this.error = null
      this.isLoadingProfile = false
    },

    applyProfile(profile) {
      this.user = {
        id: profile.id,
        keycloak_id: profile.keycloak_id,
        name: profile.name,
        email: profile.email,
      }
      this.roles = normalizeList(profile.roles)
      this.permissions = normalizeList(profile.permissions)
      this.error = null
    },

    async refreshToken(minValidity = 30) {
      if (!this.isAuthenticated) {
        return false
      }

      return keycloakUpdateToken(minValidity)
    },

    async loadProfile() {
      if (!this.isAuthenticated) {
        return
      }

      this.isLoadingProfile = true
      this.error = null

      try {
        const profile = await AuthService.getProfile()

        if (!profile) {
          throw new Error('No se pudo cargar el perfil del usuario.')
        }

        this.applyProfile(profile)
      } catch (error) {
        const status = error?.response?.status

        if (status === 401) {
          this.clearSession()

          if (!this.isLoggingIn) {
            this.isLoggingIn = true
            await keycloakLogin()
          }

          return
        }

        if (!error?.response) {
          this.error =
            'No se pudo conectar con el backend para cargar tu perfil. Verificá que la API esté disponible e intentá de nuevo.'
        } else {
          this.error = 'No se pudo cargar tu perfil. Intentá de nuevo en unos instantes.'
        }

        throw error
      } finally {
        this.isLoadingProfile = false
      }
    },

    async initialize() {
      if (this.isReady) {
        return
      }

      this.configError = null
      this.error = null

      try {
        const authenticated = await initKeycloak()
        this.isAuthenticated = authenticated || keycloakIsAuthenticated()

        if (this.isAuthenticated) {
          await this.loadProfile()
        }
      } catch (error) {
        if (error instanceof KeycloakConfigError) {
          this.configError = error.message
          this.isAuthenticated = false
        } else if (!error?.response) {
          this.error =
            'No se pudo conectar con Keycloak. Verificá que el servidor esté disponible y la configuración sea correcta.'
          this.isAuthenticated = false
        } else {
          this.error = 'Ocurrió un error al iniciar la sesión.'
          this.isAuthenticated = false
        }
      } finally {
        this.isReady = true
      }
    },

    async login() {
      this.isLoggingIn = true
      await keycloakLogin()
    },

    async logout() {
      this.clearSession()
      await keycloakLogout()
    },

    async retryProfile() {
      if (!this.isAuthenticated) {
        await this.login()
        return
      }

      await this.loadProfile()
    },
  },
})
