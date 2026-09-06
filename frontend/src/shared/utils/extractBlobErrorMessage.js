import { extractApiErrorMessage } from './extractApiErrorMessage'

const PDF_FALLBACK = 'No se pudo descargar el PDF. Intentá nuevamente.'

function isAxiosStatusNoise(message) {
  return /^Request failed with status code \d+$/i.test(String(message || ''))
}

export async function extractBlobErrorMessage(error, fallback = PDF_FALLBACK) {
  const data = error?.response?.data

  if (!(data instanceof Blob)) {
    const message = extractApiErrorMessage(error, fallback)

    return isAxiosStatusNoise(message) ? fallback : message
  }

  try {
    const parsed = JSON.parse(await data.text())
    const wrapped = {
      response: { ...error.response, data: parsed },
      isForbidden: error?.isForbidden,
      message: error?.message,
    }
    const message = extractApiErrorMessage(wrapped, fallback)

    return isAxiosStatusNoise(message) ? fallback : message
  } catch {
    return fallback
  }
}
