import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const TeamTieService = {
  async listByGroup(groupId) {
    const response = await httpClient.get(`/groups/${groupId}/team-ties`)
    return unwrap(response) ?? []
  },

  async show(id) {
    const response = await httpClient.get(`/team-ties/${id}`)
    return unwrap(response) ?? null
  },
}

export default TeamTieService
