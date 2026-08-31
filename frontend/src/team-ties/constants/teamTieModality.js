export const TEAM_TIE_MODALITY_LABELS = {
  singles: 'Individual',
  doubles: 'Dobles',
}

export function getTeamTieModalityLabel(modality) {
  return TEAM_TIE_MODALITY_LABELS[modality] ?? modality
}
