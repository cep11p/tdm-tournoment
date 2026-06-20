import { createRouter, createWebHistory } from 'vue-router'

import DashboardView from '../views/DashboardView.vue'
import TournamentListView from '../tournaments/views/TournamentListView.vue'
import TournamentCreateView from '../tournaments/views/TournamentCreateView.vue'
import TournamentDetailView from '../tournaments/views/TournamentDetailView.vue'
import CompetitionListView from '../competitions/views/CompetitionListView.vue'
import CompetitionCreateView from '../competitions/views/CompetitionCreateView.vue'
import CompetitionDetailView from '../competitions/views/CompetitionDetailView.vue'
import RegistrationListView from '../registrations/views/RegistrationListView.vue'
import GroupListView from '../groups/views/GroupListView.vue'
import GroupDetailView from '../groups/views/GroupDetailView.vue'
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
    path: '/tournaments/:id/competitions',
    name: 'competitions',
    component: CompetitionListView,
  },
  {
    path: '/tournaments/:id/competitions/create',
    name: 'competitions-create',
    component: CompetitionCreateView,
  },
  {
    path: '/competitions/:id',
    name: 'competitions-detail',
    component: CompetitionDetailView,
  },
  {
    path: '/competitions/:id/registrations',
    name: 'competitions-registrations',
    component: RegistrationListView,
  },
  {
    path: '/competitions/:id/groups',
    name: 'competitions-groups',
    component: GroupListView,
  },
  {
    path: '/groups/:id',
    name: 'groups-detail',
    component: GroupDetailView,
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
