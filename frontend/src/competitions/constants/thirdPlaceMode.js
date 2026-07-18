export const THIRD_PLACE_MODE_OPTIONS = [
  {
    value: 'shared',
    label: 'Compartido',
    description: 'Los dos perdedores de semifinales obtienen el 3.º puesto.',
  },
  {
    value: 'playoff',
    label: 'Partido por tercer puesto',
    description:
      'Los perdedores de semifinales juegan para definir 3.º y 4.º. El partido por tercer puesto será gestionado por la fase eliminatoria.',
  },
  {
    value: 'none',
    label: 'Sin tercer puesto',
    description: 'Solo se determinan campeón y subcampeón.',
  },
]

export function getThirdPlaceModeLabel(value) {
  return THIRD_PLACE_MODE_OPTIONS.find((option) => option.value === value)?.label ?? value
}
