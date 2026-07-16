import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const ClubService = {
  async list() {
    const response = await httpClient.get('/clubs')
    return unwrap(response) ?? []
  },
}

export default ClubService
