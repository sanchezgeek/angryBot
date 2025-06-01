<script setup lang="ts">

import SymbolCandlesChart from '../../modules/chart/widgets/SymbolCandlesChart.vue'
import { onMounted } from 'vue'
import { openedPositionsDataProxy } from '../positions/dataProxy/openedPositionsDataProxy'
import OpenedPositionsTable from '../../modules/opened-positions/widggets/OpenedPositionsTable.vue'
import { useRouter, useRoute } from 'vue-router'

let symbol = useRoute().params.symbol

const { openedPositions, isPositionsLoading, fetchPositions, filters } = openedPositionsDataProxy()
filters.value.symbol = symbol
fetchPositions()

// onBeforeMount(() => {loadPositionData()})
onMounted(() => {
  setInterval(() => {
    fetchPositions()
  }, 10000)
})
</script>

<template>
  <h1 class="page-title">{{ symbol }} dashboard</h1>

  <SymbolCandlesChart :symbol="symbol" :positions="openedPositions"></SymbolCandlesChart>
  <OpenedPositionsTable :opened-positions="openedPositions" :loading="isPositionsLoading"/>
</template>
