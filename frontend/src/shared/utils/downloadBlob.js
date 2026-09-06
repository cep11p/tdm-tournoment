export function filenameFromContentDisposition(header, fallback) {
  if (typeof header !== 'string' || header.trim() === '') {
    return fallback
  }

  const quoted = header.match(/filename\s*=\s*"([^"]+)"/i)
  if (quoted?.[1]) {
    return quoted[1].trim()
  }

  const unquoted = header.match(/filename\s*=\s*([^;]+)/i)
  if (unquoted?.[1]) {
    return unquoted[1].trim().replace(/^["']|["']$/g, '')
  }

  return fallback
}

export function downloadBlob(data, filename, type = 'application/pdf') {
  const blob = data instanceof Blob
    ? data
    : new Blob([data], { type: type || 'application/pdf' })

  const objectUrl = URL.createObjectURL(blob)

  try {
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = filename
    anchor.rel = 'noopener'

    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
  } finally {
    URL.revokeObjectURL(objectUrl)
  }
}

export function savePdfResponse(response, fallbackFilename) {
  const header =
    response?.headers?.['content-disposition']
    ?? response?.headers?.['Content-Disposition']

  const type =
    response?.headers?.['content-type']
    || 'application/pdf'

  const filename =
    filenameFromContentDisposition(header, fallbackFilename)

  downloadBlob(response.data, filename, type)
}
