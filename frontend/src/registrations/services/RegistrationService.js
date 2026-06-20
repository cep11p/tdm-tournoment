import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const RegistrationService = {
  async listByCompetition(competitionId) {
    const response = await httpClient.get(`/competitions/${competitionId}/registrations`)
    return unwrap(response) ?? []
  },

  async create(competitionId, playerId) {
    const response = await httpClient.post(`/competitions/${competitionId}/registrations`, {
      player_id: playerId,
    })

    return unwrap(response) ?? null
  },
}

export default RegistrationService
