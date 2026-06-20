import { createRouter, createWebHistory } from 'vue-router'

import DashboardView from '../views/DashboardView.vue'
import TournamentListView from '../tournaments/views/TournamentListView.vue'
import TournamentCreateView from '../tournaments/views/TournamentCreateView.vue'
import TournamentDetailView from '../tournaments/views/TournamentDetailView.vue'
import PlayerListView from '../players/views/PlayerListView.vue'

const routes = [
  {
    path: '/',
    name: 'dashboard',
    component: DashboardView,
  },
  {
    path: '/tournaments',
    name: 'tournaments',
    component: TournamentListView,
  },
  {
    path: '/tournaments/create',
    name: 'tournaments-create',
    component: TournamentCreateView,
  },
  {
    path: '/tournaments/:id',
    name: 'tournaments-detail',
    component: TournamentDetailView,
  },
  {
    path: '/players',
    name: 'players',
    component: PlayerListView,
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

export default router
