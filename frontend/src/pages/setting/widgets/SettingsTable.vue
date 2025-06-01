<script setup lang="ts">
import { defineVaDataTableColumns, useModal } from 'vuestic-ui'
import { Setting } from '../types'
import { PropType, toRef } from 'vue'

const columns = defineVaDataTableColumns([
  { label: 'Key', key: 'key', sortable: false },
  { label: 'Value', key: 'value', sortable: false },
  { label: ' ', key: 'actions', align: 'right' },
])

const props = defineProps({
  settingsGroups: {
    type: Array as PropType<Setting[]>,
    required: true,
  },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits<{
  (event: 'edit-setting', setting: Setting): void
  (event: 'delete-setting', setting: Setting): void
}>()

const settingsGroups = toRef(props, 'settingsGroups')

// const roleColors: Record<UserRole, string> = {
//   admin: 'danger',
//   user: 'background-element',
//   owner: 'warning',
// }

const { confirm } = useModal()

const onSettingDelete = async (setting: Setting) => {
  const agreed = await confirm({
    title: 'Delete setting',
    // @todo Key for identify setting
    message: `Are you sure you want to delete ${setting.key}?`,
    okText: 'Delete',
    cancelText: 'Cancel',
    size: 'small',
    maxWidth: '380px',
  })

  if (agreed) {
    emit('delete-setting', setting)
  }
}
</script>

<template>
  <div class="va-table-responsive">

    <table class="va-table">
      <tr>
        <th>Key</th>
        <th>Value</th>
        <th>Info</th>
      </tr>
      <template v-for="settingsGroup in settingsGroups">
        <tr>
          <th colspan="3" class="v-input--is-dirty">{{ settingsGroup.caption }}</th>
        </tr>
        <tr v-for="settingRow in settingsGroup.items">
          <td :style="[settingRow.isFallbackValue ? 'text-align: left' : 'text-align: right']">{{ settingRow.displayKey }}</td>
          <td>{{ settingRow.showValue }}</td>
          <td>{{ settingRow.info }}</td>
        </tr>
      </template>
    </table>
  </div>
</template>

<style lang="scss" scoped>
.va-data-table {
  ::v-deep(.va-data-table__table-tr) {
    border-bottom: 1px solid var(--va-background-border);
  }
}
.va-table-responsive {
  overflow: auto;
}
.va-table th {
  text-transform: none;
}
</style>
