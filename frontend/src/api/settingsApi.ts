import api from '../services/api'
import { Setting } from '../pages/setting/types'

export type Filters = {
  isActive: boolean
  search: string,
  symbol: string|null
}

export const getSettings = async (filters: Partial<Filters>) => {
  const { search, symbol } = filters
  let filteredSettings: Setting[] = await fetch(api.allSettings(symbol)).then((r) => r.json())

  // filteredSettings = filteredSettings.filter((setting) => setting.active === isActive)

  if (search) {
    filteredSettings = filteredSettings.filter((setting) => setting.key.toLowerCase().includes(search.toLowerCase()))
  }

  return {
    data: filteredSettings,
  }
}

export const addSetting = async (setting: Setting) => {
  const headers = new Headers()
  headers.append('Content-Type', 'application/json')

  const result = await fetch(api.allUsers(), { method: 'POST', body: JSON.stringify(setting), headers }).then((r) =>
    r.json(),
  )

  if (!result.error) {
    return result
  }

  throw new Error(result.error)
}

export const updateSetting = async (setting: Setting) => {
  const headers = new Headers()
  headers.append('Content-Type', 'application/json')

  // @todo Key for identify setting
  const result = await fetch(api.setting(setting.key), { method: 'PUT', body: JSON.stringify(setting), headers }).then(
    (r) => r.json(),
  )

  if (!result.error) {
    return result
  }

  throw new Error(result.error)
}

export const removeSetting = async (setting: Setting) => {
  // @todo Key for identify setting
  return fetch(api.setting(setting.key), { method: 'DELETE' })
}

export const uploadAvatar = async (body: FormData) => {
  return fetch(api.avatars(), { method: 'POST', body, redirect: 'follow' }).then((r) => r.json())
}
