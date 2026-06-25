import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const GroupService = {
  async listByCompetition(competitionId) {
    const response = await httpClient.get(`/competitions/${competitionId}/groups`)
    return unwrap(response) ?? []
  },

  async create(competitionId, payload) {
    const response = await httpClient.post(`/competitions/${competitionId}/groups`, payload)
    return unwrap(response) ?? null
  },

  async listPlayers(groupId) {
    const response = await httpClient.get(`/groups/${groupId}/players`)
    return unwrap(response) ?? []
  },

  async assignPlayer(groupId, playerId) {
    const response = await httpClient.post(`/groups/${groupId}/players`, {
      player_id: playerId,
    })
    return unwrap(response) ?? null
  },

  async generateRoundRobin(groupId) {
    const response = await httpClient.post(`/groups/${groupId}/round-robin-games`)
    return unwrap(response) ?? []
  },

  async generateRandomGroups(competitionId, { groups_count }) {
    const response = await httpClient.post(
      `/competitions/${competitionId}/groups/random-generate`,
      { groups_count },
    )

    return response?.data
  },

  async setGroupPlayerStatus(groupId, payload) {
    const response = await httpClient.post(`/groups/${groupId}/player-status`, payload)
    return unwrap(response) ?? null
  },
}

export default GroupService
