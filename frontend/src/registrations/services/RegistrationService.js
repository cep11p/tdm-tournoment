import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const RegistrationService = {
  async listByCompetition(competitionId) {
    const response = await httpClient.get(`/competitions/${competitionId}/registrations`)
    return unwrap(response) ?? []
  },

  async registerPlayer(competitionId, playerId) {
    const response = await httpClient.post(`/competitions/${competitionId}/registrations`, {
      player_id: playerId,
    })

    return unwrap(response) ?? null
  },

  async registerPair(competitionId, playerIds) {
    const response = await httpClient.post(`/competitions/${competitionId}/registrations`, {
      player_ids: playerIds,
    })

    return unwrap(response) ?? null
  },

  async registerTeam(competitionId, name, playerIds) {
    const response = await httpClient.post(`/competitions/${competitionId}/registrations`, {
      name,
      player_ids: playerIds,
    })

    return unwrap(response) ?? null
  },

  async bulkRegister(competitionId, playerIds) {
    const response = await httpClient.post(`/competitions/${competitionId}/registrations/bulk`, {
      player_ids: playerIds,
    })

    return response?.data ?? null
  },
}

export default RegistrationService
