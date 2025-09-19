<script setup lang="ts">
import SymbolCandlesChart from '../../modules/chart/widgets/SymbolCandlesChart.vue'
import {onMounted, ref, watch} from 'vue'
import { openedPositionsDataProxy } from '../positions/dataProxy/openedPositionsDataProxy'
import OpenedPositionsTable from '../../modules/opened-positions/widggets/OpenedPositionsTable.vue'
import { useRouter, useRoute } from 'vue-router'
import { marketStructureDataProxy } from '../../modules/market-structure/dataProxy/marketStructureDataProxy'

const symbol = useRoute().params.symbol as string

const selectedTimeFrame = ref('1D')
const options = ref([
  { id: '1m', text: '1 minutes' },
  { id: '3m', text: '3 minutes' },
  { id: '5m', text: '5 minutes' },
  { id: '15m', text: '15 minutes' },
  { id: '30m', text: '30 minutes' },
  { id: '1h', text: '1 hour' },
  { id: '2h', text: '2 hours' },
  { id: '3h', text: '3 hours' },
  { id: '4h', text: '4 hours' },
  { id: '6h', text: '6 hours' },
  { id: '12h', text: '12 hours' },
  { id: '1D', text: '1 day' },
  { id: '1W', text: '1 week' },
  { id: '1M', text: '1 month' },
])

const { openedPositions, isPositionsLoading, fetchPositions, filters } = openedPositionsDataProxy()
filters.value.symbol = symbol
fetchPositions()

const { structurePoints, marketStructureFilters, fetchMarketStructure } = marketStructureDataProxy()
marketStructureFilters.value.symbol = symbol

watch(
  selectedTimeFrame, (newTimeFrame) => {
    marketStructureFilters.value.timeFrame = newTimeFrame
  }, { immediate: true }
)

setInterval(() => {
  fetchMarketStructure()
}, 200000)

// onBeforeMount(() => {loadPositionData()})
onMounted(() => {
  setInterval(() => {
    fetchPositions()
  }, 10000)
})
</script>

<template>
  <h1 class="page-title">{{ symbol }} dashboard</h1>

  <select v-model="selectedTimeFrame">
    <option disabled value="">Выберите опцию</option>
    <option v-for="option in options" :key="option.id" :value="option.id">
      {{ option.text }}
    </option>
  </select>
  <br>
  <br>

  <SymbolCandlesChart
    :symbol="symbol"
    :positions="openedPositions"
    :market-structure-points="structurePoints"
    :time-frame="selectedTimeFrame"
  />

  <OpenedPositionsTable :opened-positions="openedPositions" :loading="isPositionsLoading" />
</template>
