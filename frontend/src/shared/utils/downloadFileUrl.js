import httpClient from '../../services/httpClient'

export function buildApiUrl(path) {
  const baseUrl = String(httpClient.defaults.baseURL || '').replace(/\/+$/, '')
  const normalizedPath = String(path).replace(/^\/+/, '')

  return `${baseUrl}/${normalizedPath}`
}

export function downloadFileUrl(url) {
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.rel = 'noopener'

  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
}
