import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const StandingService = {
  async listByGroup(groupId) {
    const response = await httpClient.get(`/groups/${groupId}/standings`)
    return unwrap(response) ?? []
  },
}

export default StandingService
