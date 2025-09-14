import { Ref, ref, unref, watch, computed } from 'vue'
import { MarketStructureFilters } from '../api/marketStructureApi'
import { marketStructureStore } from '../storage/marketStructureStore'

const makeFiltersRef = () => ref<Partial<MarketStructureFilters>>({})

export const marketStructureDataProxy = (options?: {
  marketStructureFilters?: Ref<Partial<MarketStructureFilters>>
}) => {
  const isLoading = ref(false)
  const error = ref()
  const structureStore = marketStructureStore()

  const { marketStructureFilters = makeFiltersRef() } = options || {}

  const fetchMarketStructure = async () => {
    isLoading.value = true
    try {
      await structureStore.getMarketStructure({
        filters: unref(marketStructureFilters),
      })
    } finally {
      isLoading.value = false
    }
  }

  watch(marketStructureFilters, () => fetchMarketStructure(), { deep: true })

  const structurePoints = computed(() => {
    return structureStore.points
  })

  return {
    error,
    isStructureLoading: isLoading,
    marketStructureFilters: marketStructureFilters,
    structurePoints: structurePoints,
    fetchMarketStructure,
  }
}
