<script setup lang="ts">

import {onMounted, ref, watch} from "vue";
import {makeCandlesFilter, getCandles} from "../api/candlesApi";

import {LightweightCharts, ChartApi, SeriesApi} from '../../../library/tv.js';
import {OpenedPositionDto} from "../../position/dto/positionTypes";

const props = defineProps({
  symbol: String,
  msg: '',
  chartOptions: {
    type: Object,
    default() {
      return {
        layout: {textColor: 'black', background: {type: 'solid', color: 'white'}},
        crosshair: {
          mode: LightweightCharts.CrosshairMode.Normal,
        }
      }
    }
  },
  positions: Array
})

// const emit = defineEmits(['response'])
// emit('response', `hello from child (intiialized with ${props.msg})`)

const candlesData = ref([]);

let chart: ChartApi
let candleSeries: SeriesApi
let shortPositionLineSeries: SeriesApi
let longPositionLineSeries: SeriesApi

async function updateChart() {
  const res = await getCandles(makeCandlesFilter(props.symbol))
  candlesData.value = res.data;
  candleSeries.setData(candlesData.value);
}

function updatePositions() {
  let candles = JSON.parse(JSON.stringify(candlesData.value));
  if (candles.length === 0) {
    return;
  }
  // console.log(candles.length)
  //
  // candlesData.value

  for (let i in props.positions) {
    let position = props.positions[i];
    if (position.side === 'sell') {
      shortPositionLineSeries.setData([{value: position.entryPrice, time: candles[0].time}, {value: position.entryPrice, time: candles[candles.length - 1].time}]);
    }
    if (position.side === 'buy') {
      longPositionLineSeries.setData([{value: position.entryPrice, time: candles[0].time}, {value: position.entryPrice, time: candles[candles.length - 1].time}]);
      // longPositionLineSeries.setMarkers([
      //   {
      //     time: candles[candles.length - 1].time,
      //     position: 'belowBar',
      //     color: 'green',
      //     shape: 'arrowUp',
      //   }]);
    }
  }
}

watch((props.positions, () => {
  updatePositions();
}))

onMounted(() => {
  console.log(props.positions)
    chart = LightweightCharts.createChart(document.getElementById("tvchart"), props.chartOptions);
    candleSeries = chart.addSeries(LightweightCharts.CandlestickSeries, { upColor: '#26a69a', downColor: '#ef5350', borderVisible: false, wickUpColor: '#26a69a', wickDownColor: '#ef5350' });
    shortPositionLineSeries = chart.addSeries(LightweightCharts.LineSeries, { color: 'red', lineWidth: 1});
    longPositionLineSeries = chart.addSeries(LightweightCharts.LineSeries, { color: 'green', lineWidth: 1});
    updateChart()
})
</script>

<template>
  <div id="tvchart" style="height: 500px; width: 500px"></div>
</template>

<style scoped>
</style>
