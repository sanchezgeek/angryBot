import { OpenedPositionDto } from '../dto/positionTypes'
import api from '../../../services/api'

// @todo some Symbol type
export type OpenedPositionsFilters = {
  symbol: string | null
}

export const getOpenedPositions = async (filters: Partial<OpenedPositionsFilters>) => {
  const openedSymbolPositions: OpenedPositionDto[] = await fetch(api.openedPositions(filters.symbol)).then((r) => r.json())

  return {
    data: openedSymbolPositions,
  }
}
