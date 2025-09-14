import { MarketStructurePointDto } from '../dto/marketStructureTypes'
import api from '../../../services/api'

// @todo some Symbol type
export type MarketStructureFilters = {
  symbol: string
  timeFrame: string
}

export const getMarketStructurePoints = async (filters: Partial<MarketStructureFilters>) => {
  const marketStructurePoints: {points: MarketStructurePointDto[]} = await fetch(
    api.marketStructure(filters.symbol ?? '', filters.timeFrame ?? ''),
  ).then((r) => r.json())

  return {
    data: marketStructurePoints,
  }
}
