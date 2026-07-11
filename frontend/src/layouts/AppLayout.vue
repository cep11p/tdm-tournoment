<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  Bars3Icon,
  HomeIcon,
  TrophyIcon,
  UsersIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

import { useTheme } from '../composables/useTheme'

const route = useRoute()

const navigationLinks = [
  { name: 'Dashboard', to: '/', icon: HomeIcon },
  { name: 'Tournaments', to: '/tournaments', icon: TrophyIcon },
  { name: 'Jugadores', to: '/players', icon: UsersIcon },
]

const navLinkBaseClasses =
  'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100'

const navLinkActiveClasses =
  'bg-slate-900 text-white hover:bg-slate-900 hover:text-white dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-100 dark:hover:text-slate-900'

const isNavItemActive = (item) => {
  if (item.to === '/tournaments') {
    return route.path.startsWith('/tournaments')
  }

  if (item.to === '/players') {
    return route.path.startsWith('/players')
  }

  if (item.to === '/') {
    return route.path === '/'
  }

  return route.path === item.to
}

const navLinkClasses = (item) => [navLinkBaseClasses, isNavItemActive(item) ? navLinkActiveClasses : '']

const { theme, toggle } = useTheme()
const isDarkMode = computed(() => theme.value === 'dark')

const isSidebarOpen = ref(false)

const toggleSidebar = () => {
  isSidebarOpen.value = !isSidebarOpen.value
}

const closeSidebar = () => {
  isSidebarOpen.value = false
}

watch(() => route.path, closeSidebar)
</script>

<template>
  <div class="min-h-screen overflow-x-hidden bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <div
      v-show="isSidebarOpen"
      class="fixed inset-0 z-40 bg-black/50 lg:hidden"
      aria-hidden="true"
      @click="closeSidebar"
    />

    <div class="mx-auto flex min-h-screen min-w-0 max-w-7xl">
      <aside
        class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-slate-200 bg-white p-4 transition-transform duration-200 ease-in-out dark:border-slate-800 dark:bg-slate-900 lg:static lg:z-auto lg:translate-x-0"
        :class="isSidebarOpen ? 'translate-x-0' : '-translate-x-full'"
      >
        <div class="mb-6 flex items-center justify-between">
          <h1 class="text-lg font-bold text-slate-900 dark:text-slate-100">TDM Frontend</h1>
          <button
            type="button"
            class="rounded-md p-1 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 lg:hidden"
            aria-label="Cerrar menú"
            @click="closeSidebar"
          >
            <XMarkIcon class="h-6 w-6" />
          </button>
        </div>

        <nav class="space-y-2">
          <RouterLink
            v-for="item in navigationLinks"
            :key="item.to"
            :to="item.to"
            :class="navLinkClasses(item)"
          >
            <component :is="item.icon" class="h-5 w-5" />
            <span>{{ item.name }}</span>
          </RouterLink>
        </nav>
      </aside>

      <div class="flex min-h-screen min-w-0 flex-1 flex-col">
        <header
          class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-900 md:px-6"
        >
          <div class="flex min-w-0 items-center gap-3">
            <button
              type="button"
              class="rounded-md p-1 text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800 lg:hidden"
              aria-label="Abrir menú"
              @click="toggleSidebar"
            >
              <Bars3Icon class="h-6 w-6" />
            </button>
            <h2 class="truncate text-base font-semibold text-slate-900 dark:text-slate-100">
              Tournament Management
            </h2>
          </div>

          <button
            type="button"
            class="shrink-0 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            @click="toggle"
          >
            {{ isDarkMode ? '🌙 Dark' : '🌞 Light' }}
          </button>
        </header>

        <main class="min-w-0 flex-1 bg-slate-100 p-4 dark:bg-slate-950 md:p-6">
          <slot />
        </main>
      </div>
    </div>
  </div>
</template>
