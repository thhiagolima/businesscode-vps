/**
 * Extracts the most actionable message from an Axios error.
 *
 * Laravel validation errors (422) return a generic `message` ("Erro de
 * validação") plus an `errors` object/array with the real per-field reason.
 * Showing only `message` hides why the request failed (e.g. "O canal 'sms'
 * não está habilitado para a sua conta"). This prefers the first field error,
 * then the array form, then the generic message, then the fallback.
 */
export function extractApiError(e: any, fallback: string): string {
  const data = e?.response?.data
  const errors = data?.errors

  if (errors) {
    if (Array.isArray(errors)) {
      if (errors.length) return errors.join('. ')
    } else if (typeof errors === 'object') {
      const first = Object.values(errors).flat()[0]
      if (typeof first === 'string' && first) return first
    }
  }

  return data?.message ?? fallback
}
