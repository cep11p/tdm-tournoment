export const rankingRuleResultOptions = [
  { key: 'champion', label: 'Campeón' },
  { key: 'runner_up', label: 'Subcampeón' },
  { key: 'third_place', label: 'Tercer puesto' },
  { key: 'fourth_place', label: 'Cuarto puesto' },
  { key: 'semifinal', label: 'Semifinal' },
  { key: 'quarterfinal', label: 'Cuartos de final' },
  { key: 'round_of_16', label: 'Octavos de final' },
  { key: 'round_of_32', label: 'Dieciseisavos' },
  { key: 'play_in', label: 'Play-in' },
  { key: 'group_stage', label: 'Fase de grupos' },
]

export const RANKING_RULE_SHARED_THIRD_NOTE =
  'Semifinal se usa cuando no hay partido por el tercer puesto: los dos semifinalistas suman los mismos puntos. Tercer puesto y Cuarto puesto se usan solo cuando la competencia define un partido por el tercer puesto.'

export const RANKING_RULES_LOCKED_NOTE =
  'Las reglas de este ranking ya no se pueden cambiar porque ya se otorgaron puntos. Un reglamento nuevo requiere un ranking nuevo. Las correcciones de resultado de una competencia siguen usando estas mismas reglas.'

export function rankingRuleResultLabel(resultKey) {
  return rankingRuleResultOptions.find((option) => option.key === resultKey)?.label || resultKey || 'Resultado'
}

export function rankingRuleStatusLabel(active) {
  return active ? 'Activa' : 'Inactiva'
}
