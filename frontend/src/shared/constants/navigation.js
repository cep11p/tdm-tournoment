export const NAV_LABEL_DASHBOARD = 'Inicio'
export const NAV_LABEL_TOURNAMENTS = 'Torneos'
export const NAV_LABEL_PLAYERS = 'Jugadores'

export const APP_BRAND_TITLE = 'Torneos TDM'
export const APP_HEADER_TITLE = 'Gestión de torneos'

export const THEME_LABEL_DARK = 'Oscuro'
export const THEME_LABEL_LIGHT = 'Claro'

export const BREADCRUMB_TOURNAMENTS = 'Torneos'
export const BREADCRUMB_BRACKET = 'Llave eliminatoria'

export const NAVIGATION_LINKS = [
  { name: NAV_LABEL_DASHBOARD, to: '/' },
  { name: NAV_LABEL_TOURNAMENTS, to: '/tournaments', permission: 'tournaments.view' },
  { name: NAV_LABEL_PLAYERS, to: '/players', permission: 'players.view' },
]
