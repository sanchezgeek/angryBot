import { OpenedPositionDto } from '../dto/positionTypes'
import api from '../../../services/api'

export type Filters = {
  symbol: string
}

export function makeCandlesFilter(symbol: string): Filters {
  return {symbol: symbol}
}

export const getCandles = async (filters: Filters) => {
  let candles = await fetch(api.candles(filters.symbol)).then((r) => r.json())

  return {
    data: candles,
  }
}
