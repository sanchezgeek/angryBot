<script setup lang="ts">
import { openedPositionsDataProxy } from './dataProxy/openedPositionsDataProxy'
import OpenedPositionsTable from '../../modules/opened-positions/widggets/OpenedPositionsTable.vue'

const { openedPositions, isPositionsLoading, fetchPositions } = openedPositionsDataProxy()
fetchPositions()
// watchEffect(() => {if (error.value) {notify({message: error.value.message,color: 'danger',})}})
</script>

<template>
  <h1 class="page-title">Settings</h1>

  <VaCard>
    <VaCardContent>
      <!--    // filters-->
      <OpenedPositionsTable :opened-positions="openedPositions" :loading="isPositionsLoading" />
    </VaCardContent>
  </VaCard>
</template>
