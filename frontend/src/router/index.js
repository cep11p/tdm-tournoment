import { createRouter, createWebHistory } from 'vue-router'

import DashboardView from '../views/DashboardView.vue'
import TournamentListView from '../tournaments/views/TournamentListView.vue'
import TournamentCreateView from '../tournaments/views/TournamentCreateView.vue'
import TournamentDetailView from '../tournaments/views/TournamentDetailView.vue'
import CompetitionCreateView from '../competitions/views/CompetitionCreateView.vue'
import CompetitionDetailView from '../competitions/views/CompetitionDetailView.vue'
import CompetitionEditView from '../competitions/views/CompetitionEditView.vue'
import RegistrationListView from '../registrations/views/RegistrationListView.vue'
import GroupDetailView from '../groups/views/GroupDetailView.vue'
import GameListView from '../games/views/GameListView.vue'
import GameDetailView from '../games/views/GameDetailView.vue'
import GroupStandingsView from '../standings/views/GroupStandingsView.vue'
import CompetitionBracketView from '../brackets/views/CompetitionBracketView.vue'
import PlayerListView from '../players/views/PlayerListView.vue'
import PlayerCreateView from '../players/views/PlayerCreateView.vue'
import PlayerEditView from '../players/views/PlayerEditView.vue'

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
    redirect: (to) => `/tournaments/${to.params.id}`,
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
    path: '/competitions/:id/edit',
    name: 'competitions-edit',
    component: CompetitionEditView,
  },
  {
    path: '/competitions/:id/registrations',
    name: 'competitions-registrations',
    component: RegistrationListView,
  },
  {
    path: '/competitions/:id/groups',
    redirect: (to) => `/competitions/${to.params.id}`,
  },
  {
    path: '/competitions/:id/games',
    name: 'competitions-games',
    component: GameListView,
  },
  {
    path: '/competitions/:id/bracket',
    name: 'competitions-bracket',
    component: CompetitionBracketView,
  },
  {
    path: '/groups/:id',
    name: 'groups-detail',
    component: GroupDetailView,
  },
  {
    path: '/groups/:id/standings',
    name: 'groups-standings',
    component: GroupStandingsView,
  },
  {
    path: '/games/:id',
    name: 'games-detail',
    component: GameDetailView,
  },
  {
    path: '/players',
    name: 'players',
    component: PlayerListView,
  },
  {
    path: '/players/create',
    name: 'players-create',
    component: PlayerCreateView,
  },
  {
    path: '/players/:id/edit',
    name: 'players-edit',
    component: PlayerEditView,
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

export default router
