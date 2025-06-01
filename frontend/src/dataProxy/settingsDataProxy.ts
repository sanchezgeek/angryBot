import { Ref, ref, unref, watch, computed } from 'vue'
import type { Filters } from '../api/settingsApi'
import { Setting } from '../pages/setting/types'
import { settingsStorage } from '../stores/settings'

const makeFiltersRef = () => ref<Partial<Filters>>({ isActive: true, search: '', symbol: 'BTCUSDT' })

export const settingsDataProxy = (options?: { filters?: Ref<Partial<Filters>> }) => {
  const isLoading = ref(false)
  const error = ref()
  const storage = settingsStorage()

  const { filters = makeFiltersRef() } = options || {}

  const fetch = async () => {
    isLoading.value = true
    try {
      await storage.getAll({
        filters: unref(filters),
      })
    } finally {
      isLoading.value = false
    }
  }

  watch(
    filters,
    () => {
      fetch()
    },
    { deep: true },
  )

  fetch()

  const settingsGroups = computed(() => {
    return storage.items
  })

  return {
    error,
    isLoading,
    filters,
    settingsGroups: settingsGroups,
    fetch,

    async add(setting: Setting) {
      isLoading.value = true
      try {
        return await storage.add(setting)
      } catch (e) {
        error.value = e
      } finally {
        isLoading.value = false
      }
    },

    async update(setting: Setting) {
      isLoading.value = true
      try {
        return await storage.update(setting)
      } catch (e) {
        error.value = e
      } finally {
        isLoading.value = false
      }
    },

    async remove(setting: Setting) {
      isLoading.value = true
      try {
        return await storage.remove(setting)
      } catch (e) {
        error.value = e
      } finally {
        isLoading.value = false
      }
    },
  }
}
