import { Ref, ref, unref, watch, computed } from 'vue'
import { OpenedPositionsFilters } from '../../../modules/position/api/positionsApi'
import { openedPositionsStore } from '../../../modules/position/storage/openedPositionsStore'

const makeFiltersRef = () => ref<Partial<OpenedPositionsFilters>>({})

export const symbolPageDataProxy = (options?: { filters?: Ref<Partial<OpenedPositionsFilters>> }) => {
  const isLoading = ref(false)
  const error = ref()
  const storage = openedPositionsStore()

  const { filters = makeFiltersRef() } = options || {}

  const fetch = async () => {
    isLoading.value = true
    try {
      await storage.getOpenedPositions({
        filters: unref(filters),
      })
    } finally {
      isLoading.value = false
    }
  }

  watch(filters, () => fetch(),{ deep: true })
  fetch()

  const openedPositions = computed(() => {
    return storage.items
  })

  return {
    error,
    isLoading,
    filters,
    openedPositions: openedPositions,
    fetch,
  }
}
