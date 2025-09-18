<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { makeCandlesFilter, getCandles } from '../api/candlesApi'

import {
  createSeriesMarkers,
  LineSeries,
  AreaSeries,
  BarSeries,
  BaselineSeries,
  CandlestickSeries,
  createChart,
  IChartApi,
  ISeriesApi,
  ISeriesMarkersPluginApi,
  CrosshairMode, IPriceLine,
} from 'lightweight-charts'
import {MarketStructurePointDto} from "../../market-structure/dto/marketStructureTypes";
import {getInstrumentInfo} from "../../instrument/api/instrumentApi";

const props = defineProps({
  symbol: String,
  chartOptions: {
    type: Object,
    default() {
      return {
        layout: { textColor: 'black', background: { type: 'solid', color: 'white' } },
        crosshair: {
          mode: CrosshairMode.Normal,
        },
        width: 1000,
        timeScale: {
          timeVisible: true, // Показывать время на шкале
          secondsVisible: false, // Скрывать секунды для 4H-данных
        },
      }
    },
  },
  positions: Array,
  marketStructurePoints: Array,
  timeFrame: String,
})

// const emit = defineEmits(['response'])
// emit('response', `hello from child (intiialized with ${props.msg})`)

const candlesData = ref([])

let chart: IChartApi
// @ts-ignore
let candleSeries: ISeriesApi
// @ts-ignore
let shortPositionLineSeries: ISeriesApi
// @ts-ignore
let longPositionLineSeries: ISeriesApi
// @ts-ignore
let seriesMarkers: ISeriesMarkersPluginApi
// @ts-ignore
let structureLineSeries: ISeriesApi
// @ts-ignore
let priceLine: IPriceLine

watch(
  () => props.timeFrame,
  (newTimeFrame) => {
    updateChart()
  },
  { immediate: true },
) // immediate: true запустит watcher сразу после монтирования

watch(
  () => props.positions,
  (newPositions) => {
    updatePositions()
  },
)

watch(
  () => props.marketStructurePoints,
  (newStructurePoints) => {
    updateStructure()
  },
)

function fit() {
  chart.timeScale().fitContent()
}

async function updateChart() {
  const res = await getCandles(makeCandlesFilter(props.symbol ?? '', props.timeFrame ?? ''))
  candlesData.value = res.data
  candleSeries.setData(candlesData.value)

  const candles = JSON.parse(JSON.stringify(candlesData.value))

  if (priceLine !== undefined) {
    candleSeries.removePriceLine(priceLine);
  }

  let lastCandle = candles[candles.length - 1];
  let priceLineColor = lastCandle.close >= lastCandle.open ? 'green' : 'red';
  priceLine = candleSeries.createPriceLine({
    price: lastCandle.close,
    color: priceLineColor,
    lineWidth: 2,
    lineStyle: 2,
    axisLabelVisible: true,
    title: '',
  });
}

async function setupInstrumentInfo(symbol: string) {
  const info = await getInstrumentInfo(symbol)

  candleSeries.applyOptions({
    priceFormat: {
      type: 'price', // Тип формата: 'price', 'volume', 'percent' или 'custom'
      minMove: info.info.tickSize, // Минимальный шаг цены. Например, 0.0001 для 4 десятичных знаков
      precision: info.info.priceScale // Количество знаков после запятой (для большей точности)
    }
  })
}

function updateStructure() {
  let marketStructurePoints = props.marketStructurePoints as Array<MarketStructurePointDto>

  const lineData = []
  for (let i = 0; i < marketStructurePoints.length - 1; i++) {
    const currentPoint = marketStructurePoints[i]

    // markers.push({time: currentPoint.time,position: currentPoint.type === 'peak' ? 'aboveBar' : 'belowBar', color: 'red', shape: 'circle', size: 1});
    lineData.push(
      { time: currentPoint.time, value: currentPoint.price },
    )
  }
  // try {seriesMarkers.setMarkers([]);seriesMarkers.setMarkers(markers);} catch (error) {console.error('Ошибка при установке маркеров:', error);}

  try {
    structureLineSeries.setData(lineData)
  } catch (error) { console.error('Ошибка при установке маркеров:', error) }
}

function updatePositions() {
  const candles = JSON.parse(JSON.stringify(candlesData.value))
  if (candles.length === 0) {
    return
  }

  for (const i in props.positions) {
    // @ts-ignore
    const position = props.positions[i]
    if (position.side === 'sell') {
      shortPositionLineSeries.setData([
        { value: position.entryPrice, time: candles[0].time },
        { value: position.entryPrice, time: candles[candles.length - 1].time },
      ])
    }
    if (position.side === 'buy') {
      longPositionLineSeries.setData([
        { value: position.entryPrice, time: candles[0].time },
        { value: position.entryPrice, time: candles[candles.length - 1].time },
      ])
    }
  }
}

onMounted(() => {
  let element = document.getElementById('tvchart') as HTMLElement
  chart = createChart(element, props.chartOptions)
  candleSeries = chart.addSeries(CandlestickSeries, {
    upColor: '#26a69a',
    downColor: '#ef5350',
    borderVisible: true,
    wickUpColor: '#26a69a',
    wickDownColor: '#ef5350',
    lastValueVisible: false
  })

  setupInstrumentInfo(props.symbol ?? '')

  shortPositionLineSeries = chart.addSeries(LineSeries, { color: 'red', lineWidth: 1 })
  longPositionLineSeries = chart.addSeries(LineSeries, { color: 'green', lineWidth: 1 })
  structureLineSeries = chart.addSeries(LineSeries, { color: '#47aEFA', lineWidth: 1 })

  setInterval(() => {
    updateChart()
  }, 10000)

  updateStructure()

  chart.timeScale().fitContent()

  seriesMarkers = createSeriesMarkers(candleSeries, [
    {
      color: 'red',
      position: 'inBar',
      shape: 'arrowDown',
      time: 1556880900,
    },
  ])
})
</script>

<template>
  <va-button @click="fit">Fit content</va-button>
  <br><br>
  <div id="tvchart" style="height: 500px; width: 500px" />
</template>

<style scoped></style>
