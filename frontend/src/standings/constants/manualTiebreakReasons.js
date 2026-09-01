const BASE_MANUAL_TIEBREAK_REASONS = [
  { value: 'draw', label: 'Sorteo' },
  { value: 'organizer_decision', label: 'Decisión organizativa' },
  { value: 'agreement', label: 'Acuerdo entre jugadores' },
  { value: 'other', label: 'Otro' },
]

export const MANUAL_TIEBREAK_REASONS = BASE_MANUAL_TIEBREAK_REASONS

export const getManualTiebreakReasons = (isTeam = false) =>
  BASE_MANUAL_TIEBREAK_REASONS.map((reason) => {
    if (reason.value === 'agreement' && isTeam) {
      return { ...reason, label: 'Acuerdo entre equipos' }
    }

    return reason
  })
