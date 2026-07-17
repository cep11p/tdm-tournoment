import Keycloak from 'keycloak-js'

import { getKeycloakConfig } from '../config/runtime'

/** @type {Keycloak | null} */
let keycloakInstance = null

/** @type {Promise<boolean> | null} */
let initPromise = null

/** @type {ReturnType<typeof getKeycloakConfig> | null} */
let resolvedConfig = null

function createKeycloakInstance() {
  if (keycloakInstance) {
    return keycloakInstance
  }

  resolvedConfig = getKeycloakConfig()

  keycloakInstance = new Keycloak({
    url: resolvedConfig.url,
    realm: resolvedConfig.realm,
    clientId: resolvedConfig.clientId,
  })

  return keycloakInstance
}

export function getKeycloakInstance() {
  return keycloakInstance
}

export function getKeycloakRuntimeConfig() {
  return resolvedConfig
}

/**
 * Inicializa Keycloak una sola vez con Authorization Code + PKCE (S256).
 *
 * checkLoginIframe está deshabilitado: evita iframes silenciosos que suelen
 * fallar en desarrollo por cookies de terceros o CORS. El SSO silencioso
 * requeriría VITE_KEYCLOAK_SILENT_CHECK_SSO_REDIRECT_URI y una página dedicada.
 */
export function initKeycloak() {
  if (initPromise) {
    return initPromise
  }

  const keycloak = createKeycloakInstance()
  const config = resolvedConfig ?? getKeycloakConfig()

  initPromise = keycloak
    .init({
      onLoad: config.onLoad,
      pkceMethod: 'S256',
      checkLoginIframe: false,
      redirectUri: config.redirectUri,
      silentCheckSsoRedirectUri: config.silentCheckSsoRedirectUri,
    })
    .then((authenticated) => authenticated)
    .catch((error) => {
      initPromise = null
      throw error
    })

  return initPromise
}

export function isAuthenticated() {
  return Boolean(keycloakInstance?.authenticated)
}

export function getToken() {
  if (!keycloakInstance?.authenticated) {
    return null
  }

  return keycloakInstance.token ?? null
}

export function updateToken(minValidity = 30) {
  if (!keycloakInstance?.authenticated) {
    return Promise.resolve(false)
  }

  return keycloakInstance.updateToken(minValidity)
}

export function login(options = {}) {
  const config = resolvedConfig ?? getKeycloakConfig()

  return createKeycloakInstance().login({
    redirectUri: config.redirectUri,
    ...options,
  })
}

export function logout(options = {}) {
  const config = resolvedConfig ?? getKeycloakConfig()

  return createKeycloakInstance().logout({
    redirectUri: config.redirectUri,
    ...options,
  })
}
