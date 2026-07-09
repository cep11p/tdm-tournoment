import axios from 'axios'

/**
 * Prefer VITE_API_URL when set. Otherwise use the same hostname as the page
 * (localhost on the PC, LAN IP on a phone) so both work without env changes.
 */
function resolveApiBaseUrl() {
  if (import.meta.env.VITE_API_URL) {
    return import.meta.env.VITE_API_URL
  }

  if (typeof window !== 'undefined' && window.location?.hostname) {
    return `http://${window.location.hostname}:8080/api/v1`
  }

  return 'http://localhost:8080/api/v1'
}

const httpClient = axios.create({
  baseURL: resolveApiBaseUrl(),
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

export default httpClient
