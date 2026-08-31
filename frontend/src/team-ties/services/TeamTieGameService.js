import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const TeamTieGameService = {
  async setLineup(teamTieGameId, payload) {
    const response = await httpClient.post(`/team-tie-games/${teamTieGameId}/lineup`, payload)
    return unwrap(response) ?? null
  },
}

export default TeamTieGameService
