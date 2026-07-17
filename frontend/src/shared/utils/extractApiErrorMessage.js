const GENERIC_VALIDATION_MESSAGES = new Set([
  'The given data was invalid.',
  'Los datos proporcionados no son válidos.',
])

const firstValidationError = (errors) => {
  if (!errors || typeof errors !== 'object') {
    return null
  }

  for (const messages of Object.values(errors)) {
    if (Array.isArray(messages) && messages[0]) {
      return String(messages[0])
    }

    if (typeof messages === 'string' && messages.trim()) {
      return messages.trim()
    }
  }

  return null
}

export function extractApiErrorMessage(
  error,
  fallback = 'Ocurrió un error inesperado.',
) {
  if (error?.isForbidden) {
    return error.message || fallback
  }

  const data = error?.response?.data

  const validationMessage = firstValidationError(data?.errors)

  if (validationMessage) {
    return validationMessage
  }

  const apiMessage = data?.message

  if (apiMessage && !GENERIC_VALIDATION_MESSAGES.has(apiMessage)) {
    return apiMessage
  }

  if (error?.message) {
    return error.message
  }

  return fallback
}
