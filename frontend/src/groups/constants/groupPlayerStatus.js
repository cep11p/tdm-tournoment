export const GROUP_PLAYER_STATUSES = [
  { value: 'withdrawn', label: 'Retirado' },
  { value: 'disqualified', label: 'Descalificado' },
]

export const GROUP_PLAYER_STATUS_REASONS = [
  { value: '', label: 'Sin especificar' },
  { value: 'personal', label: 'Personal' },
  { value: 'injury', label: 'Lesión' },
  { value: 'no_show', label: 'No se presentó' },
  { value: 'organizer_decision', label: 'Decisión organizativa' },
  { value: 'other', label: 'Otro' },
]

export const GROUP_PLAYER_STATUS_LABELS = {
  active: null,
  withdrawn: 'Retirado',
  disqualified: 'Descalificado',
}

export const getGroupPlayerStatusLabel = (status) =>
  GROUP_PLAYER_STATUS_LABELS[status] ?? null

export const getGroupPlayerStatusReasonLabel = (reason) =>
  GROUP_PLAYER_STATUS_REASONS.find((item) => item.value === reason)?.label ?? null
