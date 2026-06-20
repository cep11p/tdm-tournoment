import { ref } from 'vue'

const THEME_STORAGE_KEY = 'theme'
const LIGHT_THEME = 'light'
const DARK_THEME = 'dark'

const theme = ref(LIGHT_THEME)

const isBrowser = () => typeof window !== 'undefined' && typeof document !== 'undefined'

const applyThemeClass = (value) => {
  if (!isBrowser()) {
    return
  }

  document.documentElement.classList.toggle(DARK_THEME, value === DARK_THEME)
}

const persistTheme = (value) => {
  if (!isBrowser()) {
    return
  }

  window.localStorage.setItem(THEME_STORAGE_KEY, value)
}

const resolveSavedTheme = () => {
  if (!isBrowser()) {
    return LIGHT_THEME
  }

  const savedTheme = window.localStorage.getItem(THEME_STORAGE_KEY)
  return savedTheme === DARK_THEME ? DARK_THEME : LIGHT_THEME
}

const setTheme = (value) => {
  const normalizedTheme = value === DARK_THEME ? DARK_THEME : LIGHT_THEME
  theme.value = normalizedTheme
  applyThemeClass(normalizedTheme)
  persistTheme(normalizedTheme)
}

const initializeTheme = () => {
  const initialTheme = resolveSavedTheme()
  theme.value = initialTheme
  applyThemeClass(initialTheme)
}

const toggle = () => {
  setTheme(theme.value === DARK_THEME ? LIGHT_THEME : DARK_THEME)
}

export function useTheme() {
  return {
    theme,
    initializeTheme,
    setTheme,
    toggle,
  }
}
