import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const BracketService = {
  async show(competitionId) {
    try {
      const response = await httpClient.get(`/competitions/${competitionId}/bracket`)
      return unwrap(response) ?? null
    } catch (error) {
      if (error?.response?.status === 404) {
        return null
      }

      throw error
    }
  },

  async create(competitionId, payload) {
    const response = await httpClient.post(`/competitions/${competitionId}/bracket`, payload)
    return unwrap(response) ?? null
  },

  async generateNextRound(bracketId) {
    const response = await httpClient.post(`/brackets/${bracketId}/next-round`)
    return unwrap(response) ?? null
  },
}

export default BracketService
