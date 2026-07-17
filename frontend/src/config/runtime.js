const REQUIRED_KEYCLOAK_VARS = [
  ['VITE_KEYCLOAK_URL', 'URL del servidor Keycloak'],
  ['VITE_KEYCLOAK_REALM', 'Realm de Keycloak'],
  ['VITE_KEYCLOAK_CLIENT_ID', 'Client ID del frontend'],
]

const VALID_ON_LOAD_VALUES = new Set(['login-required', 'check-sso'])

export class KeycloakConfigError extends Error {
  constructor(message) {
    super(message)
    this.name = 'KeycloakConfigError'
  }
}

function readEnv(name) {
  const value = import.meta.env[name]

  if (typeof value !== 'string') {
    return ''
  }

  return value.trim()
}

export function resolveKeycloakRedirectUri() {
  if (typeof window !== 'undefined' && window.location?.origin) {
    return readEnv('VITE_KEYCLOAK_REDIRECT_URI') || window.location.origin
  }

  return readEnv('VITE_KEYCLOAK_REDIRECT_URI')
}

export function getKeycloakConfig() {
  const missing = []

  for (const [name, label] of REQUIRED_KEYCLOAK_VARS) {
    if (!readEnv(name)) {
      missing.push(`${name} (${label})`)
    }
  }

  if (missing.length > 0) {
    throw new KeycloakConfigError(
      `Faltan variables de entorno de Keycloak: ${missing.join(', ')}. Revisá frontend/.env.example.`,
    )
  }

  const onLoad = readEnv('VITE_KEYCLOAK_ON_LOAD') || 'login-required'

  if (!VALID_ON_LOAD_VALUES.has(onLoad)) {
    throw new KeycloakConfigError(
      `VITE_KEYCLOAK_ON_LOAD debe ser "login-required" o "check-sso". Valor actual: "${onLoad}".`,
    )
  }

  const redirectUri = resolveKeycloakRedirectUri()

  if (!redirectUri) {
    throw new KeycloakConfigError(
      'No se pudo resolver la redirect URI de Keycloak. Definí VITE_KEYCLOAK_REDIRECT_URI o accedé desde un navegador.',
    )
  }

  return {
    url: readEnv('VITE_KEYCLOAK_URL'),
    realm: readEnv('VITE_KEYCLOAK_REALM'),
    clientId: readEnv('VITE_KEYCLOAK_CLIENT_ID'),
    redirectUri,
    silentCheckSsoRedirectUri: readEnv('VITE_KEYCLOAK_SILENT_CHECK_SSO_REDIRECT_URI') || undefined,
    onLoad,
  }
}
