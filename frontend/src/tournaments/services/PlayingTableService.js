import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const PlayingTableService = {
  async list(tournamentId) {
    const response = await httpClient.get(`/tournaments/${tournamentId}/playing-tables`)
    return unwrap(response) ?? []
  },

  async create(tournamentId, payload) {
    const response = await httpClient.post(`/tournaments/${tournamentId}/playing-tables`, payload)
    return unwrap(response) ?? null
  },

  async update(tournamentId, tableId, payload) {
    const response = await httpClient.patch(
      `/tournaments/${tournamentId}/playing-tables/${tableId}`,
      payload,
    )
    return unwrap(response) ?? null
  },

  async remove(tournamentId, tableId) {
    await httpClient.delete(`/tournaments/${tournamentId}/playing-tables/${tableId}`)
  },
}

export default PlayingTableService
