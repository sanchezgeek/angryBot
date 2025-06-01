const apiBaseUrl = import.meta.env.VITE_API_BASE_URL

export default {
  allUsers: () => `${apiBaseUrl}/users`,
  user: (id: string) => `${apiBaseUrl}/users/${id}`,
  users: ({ page, pageSize }: { page: number; pageSize: number }) =>
    `${apiBaseUrl}/users/?page=${page}&pageSize=${pageSize}`,

  allSettings: (symbol: string | null) =>
    symbol ? `${apiBaseUrl}/settings/list/${symbol}` : `${apiBaseUrl}/settings/list/`,

  setting: (id: string) => `${apiBaseUrl}/users/${id}`,

  // symbolOpenedPositions: (symbol: string) => `${apiBaseUrl}/positions/opened-positions/${symbol}`,
  openedPositions: (symbol: string | null | undefined) =>
    symbol ? `${apiBaseUrl}/positions/opened-positions/${symbol}` : `${apiBaseUrl}/positions/opened-positions/`,

  candles: (symbol: string) => `${apiBaseUrl}/chart/candles/list/${symbol}`,

  allProjects: () => `${apiBaseUrl}/projects`,
  project: (id: string) => `${apiBaseUrl}/projects/${id}`,
  projects: ({ page, pageSize }: { page: number; pageSize: number }) =>
    `${apiBaseUrl}/projects/?page=${page}&pageSize=${pageSize}`,
  avatars: () => `${apiBaseUrl}/avatars`,
}
