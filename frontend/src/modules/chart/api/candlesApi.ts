import api from '../../../services/api'

export type Filters = {
  symbol: string
  timeFrame: string
}

export function makeCandlesFilter(symbol: string, timeFrame: string): Filters {
  return { symbol: symbol, timeFrame: timeFrame }
}

export const getCandles = async (filters: Filters) => {
  const candles = await fetch(api.candles(filters.symbol, filters.timeFrame)).then((r) => r.json())

  return {
    data: candles,
  }
}
