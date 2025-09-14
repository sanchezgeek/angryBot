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
  { id: '1h', text: '1H' },
  { id: '4h', text: '4H' },
  { id: '1D', text: '1D' },
])

const { openedPositions, isPositionsLoading, fetchPositions, filters } = openedPositionsDataProxy()
filters.value.symbol = symbol

const { structurePoints, marketStructureFilters, fetchMarketStructure } = marketStructureDataProxy()
marketStructureFilters.value.symbol = symbol

watch(
  selectedTimeFrame, (newTimeFrame) => {
    marketStructureFilters.value.timeFrame = newTimeFrame
  }, { immediate: true }
)

// onBeforeMount(() => {loadPositionData()})
onMounted(() => {
  setInterval(() => {
    fetchPositions()
  }, 10000)
})
</script>

<template>
  <h1 class="page-title">{{ symbol }} dashboard</h1>

  <SymbolCandlesChart
    :symbol="symbol"
    :positions="openedPositions"
    :market-structure-points="structurePoints"
    :time-frame="selectedTimeFrame"
  />

  <select v-model="selectedTimeFrame">
    <option disabled value="">Выберите опцию</option>
    <option v-for="option in options" :key="option.id" :value="option.id">
      {{ option.text }}
    </option>
  </select>

  <OpenedPositionsTable :opened-positions="openedPositions" :loading="isPositionsLoading" />
</template>
