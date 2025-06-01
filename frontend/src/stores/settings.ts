import { defineStore } from 'pinia'
import { addSetting, type Filters, getSettings, removeSetting, updateSetting, uploadAvatar } from '../api/settingsApi'
import { Setting } from '../pages/setting/types'

export const settingsStorage = defineStore('settings', {
  state: () => {
    return {
      items: [] as Setting[],
      // pagination: { page: 1, perPage: 10, total: 0 },
    }
  },

  actions: {
    async getAll(options: { filters?: Partial<Filters> }) {
      const { data } = await getSettings({
        ...options.filters,
      })
      this.items = data
    },

    async add(setting: Setting) {
      const [newUser] = await addSetting(setting)
      this.items.unshift(newUser)
      return newUser
    },

    async update(setting: Setting) {
      const [updatedUser] = await updateSetting(setting)
      // @todo Key for identify setting
      const index = this.items.findIndex(({ key }) => key === setting.key)
      this.items.splice(index, 1, updatedUser)
      return updatedUser
    },

    async remove(setting: Setting) {
      const isRemoved = await removeSetting(setting)

      if (isRemoved) {
        // @todo Key for identify setting
        const index = this.items.findIndex(({ key }) => key === setting.key)
        this.items.splice(index, 1)
      }
    },

    async uploadAvatar(formData: FormData) {
      return uploadAvatar(formData)
    },
  },
})
