<script setup lang="ts">
import { defineVaDataTableColumns } from 'vuestic-ui'
import { OpenedPositionDto } from '../../position/dto/positionTypes'
import { PropType } from 'vue'

const columns = defineVaDataTableColumns([
  { label: 'Symbol', key: 'symbol', sortable: false },
  { label: 'Entry', key: 'entryPrice', sortable: false },
])

const props = defineProps({
  openedPositions: {
    type: Array as PropType<OpenedPositionDto[]>,
    required: true,
  },
  loading: { type: Boolean, default: false },
})

// const openedPositions = toRef(props, 'openedPositions')
</script>

<template>
  <VaDataTable :columns="columns" :items="$props.openedPositions" :loading="$props.loading">
    <template #cell(symbol)="{ rowData }">
      <div class="gap-2 ellipsis" :class="rowData.side === 'sell' ? 'short' : 'long'">
        <a :href="`/admin/dashboard/symbol-page/${rowData.symbol}`">{{ rowData.symbol }}</a>
      </div>
    </template>

    <template #cell(entryPrice)="{ rowData }">
      <div class="ellipsis">
        {{ rowData.entryPrice }}
      </div>
    </template>
  </VaDataTable>
</template>

<style lang="scss" scoped>
.va-data-table {
  ::v-deep(.va-data-table__table-tr) {
    border-bottom: 1px solid var(--va-background-border);
  }
}

.short {
  color: red;
}
.long {
  color: green;
}
</style>
