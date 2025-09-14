import { defineStore } from 'pinia'
import { getMarketStructurePoints, MarketStructureFilters } from '../api/marketStructureApi'
import { MarketStructurePointDto } from '../dto/marketStructureTypes'

export const marketStructureStore = defineStore('market-structure', {
  state: () => {
    return {
      points: [] as MarketStructurePointDto[],
      // pagination: { page: 1, perPage: 10, total: 0 },
    }
  },

  actions: {
    async getMarketStructure(options: { filters: Partial<MarketStructureFilters> }) {
      const { data } = await getMarketStructurePoints(options.filters)
      this.points = data.points
    },
  },
})
