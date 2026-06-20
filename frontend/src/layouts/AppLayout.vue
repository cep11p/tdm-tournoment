<script setup>
import { HomeIcon, TrophyIcon, UsersIcon } from '@heroicons/vue/24/outline'

const navigationLinks = [
  { name: 'Dashboard', to: '/', icon: HomeIcon },
  { name: 'Tournaments', to: '/tournaments', icon: TrophyIcon },
  { name: 'Players', to: '/players', icon: UsersIcon },
]
</script>

<template>
  <div class="min-h-screen bg-slate-100 text-slate-900">
    <div class="mx-auto flex min-h-screen max-w-7xl">
      <aside class="w-64 border-r border-slate-200 bg-white p-4">
        <h1 class="mb-6 text-lg font-bold">TDM Frontend</h1>

        <nav class="space-y-2">
          <RouterLink
            v-for="item in navigationLinks"
            :key="item.to"
            :to="item.to"
            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
            active-class="bg-slate-900 text-white hover:bg-slate-900 hover:text-white"
          >
            <component :is="item.icon" class="h-5 w-5" />
            <span>{{ item.name }}</span>
          </RouterLink>
        </nav>
      </aside>

      <div class="flex min-h-screen flex-1 flex-col">
        <header class="border-b border-slate-200 bg-white px-6 py-4">
          <h2 class="text-base font-semibold">Tournament Management</h2>
        </header>

        <main class="flex-1 p-6">
          <slot />
        </main>
      </div>
    </div>
  </div>
</template>
