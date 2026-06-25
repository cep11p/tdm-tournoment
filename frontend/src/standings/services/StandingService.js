import httpClient from '../../services/httpClient'

const StandingService = {
  async listByGroup(groupId) {
    const response = await httpClient.get(`/groups/${groupId}/standings`)

    return {
      standings: response?.data?.data ?? [],
      meta: response?.data?.meta ?? {},
    }
  },

  async applyManualTiebreak(groupId, payload) {
    const response = await httpClient.post(`/groups/${groupId}/manual-tiebreaks`, payload)

    return response?.data?.data ?? null
  },
}

export default StandingService
