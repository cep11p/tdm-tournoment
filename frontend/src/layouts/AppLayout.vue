<script setup>
import { computed } from 'vue'
import { HomeIcon, TrophyIcon, UsersIcon } from '@heroicons/vue/24/outline'

import { useTheme } from '../composables/useTheme'

const navigationLinks = [
  { name: 'Dashboard', to: '/', icon: HomeIcon },
  { name: 'Tournaments', to: '/tournaments', icon: TrophyIcon },
  { name: 'Jugadores', to: '/players', icon: UsersIcon },
]

const { theme, toggle } = useTheme()
const isDarkMode = computed(() => theme.value === 'dark')
</script>

<template>
  <div class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <div class="mx-auto flex min-h-screen max-w-7xl">
      <aside class="w-64 border-r border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h1 class="mb-6 text-lg font-bold text-slate-900 dark:text-slate-100">TDM Frontend</h1>

        <nav class="space-y-2">
          <RouterLink
            v-for="item in navigationLinks"
            :key="item.to"
            :to="item.to"
            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100"
            active-class="bg-slate-900 text-white hover:bg-slate-900 hover:text-white dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-100 dark:hover:text-slate-900"
          >
            <component :is="item.icon" class="h-5 w-5" />
            <span>{{ item.name }}</span>
          </RouterLink>
        </nav>
      </aside>

      <div class="flex min-h-screen flex-1 flex-col">
        <header
          class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4 dark:border-slate-800 dark:bg-slate-900"
        >
          <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
            Tournament Management
          </h2>

          <button
            type="button"
            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            @click="toggle"
          >
            {{ isDarkMode ? '🌙 Dark' : '🌞 Light' }}
          </button>
        </header>

        <main class="flex-1 bg-slate-100 p-6 dark:bg-slate-950">
          <slot />
        </main>
      </div>
    </div>
  </div>
</template>
