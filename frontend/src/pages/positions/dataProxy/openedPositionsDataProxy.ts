import { Ref, ref, unref, watch, computed } from 'vue'
import { OpenedPositionsFilters } from '../../../modules/position/api/positionsApi'
import { openedPositionsStore } from '../../../modules/position/storage/openedPositionsStore'

const makeFiltersRef = () => ref<Partial<OpenedPositionsFilters>>({})

export const openedPositionsDataProxy = (options?: { filters?: Ref<Partial<OpenedPositionsFilters>> }) => {
  const isLoading = ref(false)
  const error = ref()
  const storage = openedPositionsStore()

  const { filters = makeFiltersRef() } = options || {}

  const fetchPositions = async () => {
    isLoading.value = true
    try {
      await storage.getOpenedPositions({
        filters: unref(filters),
      })
    } finally {
      isLoading.value = false
    }
  }

  watch(filters, () => fetchPositions(),{ deep: true })

  const openedPositions = computed(() => {
    return storage.items
  })

  return {
    error,
    isPositionsLoading: isLoading,
    filters,
    openedPositions: openedPositions,
    fetchPositions
  }
}
