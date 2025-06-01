import { defineStore } from 'pinia'
import {getOpenedPositions, OpenedPositionsFilters} from '../api/positionsApi'
import { OpenedPositionDto } from '../dto/positionTypes'

export const openedPositionsStore = defineStore('opened-positions', {
  state: () => {
    return {
      items: [] as OpenedPositionDto[],
      // pagination: { page: 1, perPage: 10, total: 0 },
    }
  },

  actions: {
    async getOpenedPositions( options: {filters: Partial<OpenedPositionsFilters> }) {
      const { data } = await getOpenedPositions(options.filters)
      this.items = data
    },
  },
})
