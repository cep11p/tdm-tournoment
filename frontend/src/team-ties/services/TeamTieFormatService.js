import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const TeamTieFormatService = {
  async list() {
    const response = await httpClient.get('/team-tie-formats')
    return unwrap(response) ?? []
  },

  async show(id) {
    const response = await httpClient.get(`/team-tie-formats/${id}`)
    return unwrap(response) ?? null
  },
}

export default TeamTieFormatService
