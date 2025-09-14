import api from '../../../services/api'
import {InstrumentInfoDto} from "../dto/instrumentTypes";

export const getInstrumentInfo = async (symbol: string) => {
  const data: {info: InstrumentInfoDto} = await fetch(
    api.instrumentInfo(symbol),
  ).then((r) => r.json())

  return {
    info: data.info,
  }
}
