<script setup lang="ts">
import { ref, watchEffect } from 'vue'
// import EditSettingForm from './widgets/EditUserForm.vue'
import { Setting } from './types'
import { settingsDataProxy } from '../../dataProxy/settingsDataProxy'
// import { useModal, useToast } from 'vuestic-ui'
import { useToast } from 'vuestic-ui'
import SettingsTable from './widgets/SettingsTable.vue'

const doShowEditUserModal = ref(false)

const { settingsGroups, isLoading, filters, error, ...usersApi } = settingsDataProxy(null)

const userToEdit = ref<Setting | null>(null)

const showEditUserModal = (setting: Setting) => {
  userToEdit.value = setting
  doShowEditUserModal.value = true
}

const showAddUserModal = () => {
  userToEdit.value = null
  doShowEditUserModal.value = true
}

const { init: notify } = useToast()

watchEffect(() => {
  if (error.value) {
    notify({
      message: error.value.message,
      color: 'danger',
    })
  }
})

// const onUserSaved = async (user: User) => {
//   if (userToEdit.value) {
//     await usersApi.update(user)
//     if (!error.value) {
//       notify({
//         message: `${user.fullname} has been updated`,
//         color: 'success',
//       })
//     }
//   } else {
//     await usersApi.add(user)
//
//     if (!error.value) {
//       notify({
//         message: `${user.fullname} has been created`,
//         color: 'success',
//       })
//     }
//   }
// }

const onUserDelete = async (setting: Setting) => {
  await usersApi.remove(setting)
  notify({
    // @todo Key for identify setting
    message: `${setting.key} has been deleted`,
    color: 'success',
  })
}

// const editFormRef = ref()

// const { confirm } = useModal()

// const beforeEditFormModalClose = async (hide: () => unknown) => {
//   if (editFormRef.value.isFormHasUnsavedChanges) {
//     const agreed = await confirm({
//       maxWidth: '380px',
//       message: 'Form has unsaved changes. Are you sure you want to close it?',
//       size: 'small',
//     })
//     if (agreed) {
//       hide()
//     }
//   } else {
//     hide()
//   }
// }
</script>

<template>
  <h1 class="page-title">Settings</h1>

  <VaCard>
    <VaCardContent>
      <div class="flex flex-col md:flex-row gap-2 mb-2 justify-between">
        <div class="flex flex-col md:flex-row gap-2 justify-start">
          <VaButtonToggle
            v-model="filters.isActive"
            color="background-element"
            border-color="background-element"
            :options="[
              { label: 'Active', value: true },
              { label: 'Inactive', value: false },
            ]"
          />
          <VaInput v-model="filters.search" placeholder="Search">
            <template #prependInner>
              <VaIcon name="search" color="secondary" size="small" />
            </template>
          </VaInput>
        </div>
        <VaButton @click="showAddUserModal">Add User</VaButton>
      </div>

      <SettingsTable
        :settingsGroups="settingsGroups"
        :loading="isLoading"
        @editUser="showEditUserModal"
        @deleteUser="onUserDelete"
      />
    </VaCardContent>
  </VaCard>

  <!--  <VaModal-->
  <!--    v-slot="{ cancel, ok }"-->
  <!--    v-model="doShowEditUserModal"-->
  <!--    size="small"-->
  <!--    mobile-fullscreen-->
  <!--    close-button-->
  <!--    hide-default-actions-->
  <!--    :before-cancel="beforeEditFormModalClose"-->
  <!--  >-->
  <!--    <h1 class="va-h5">{{ userToEdit ? 'Edit user' : 'Add user' }}</h1>-->
  <!--    <EditSettingForm-->
  <!--      ref="editFormRef"-->
  <!--      :user="userToEdit"-->
  <!--      :save-button-label="userToEdit ? 'Save' : 'Add'"-->
  <!--      @close="cancel"-->
  <!--      @save="-->
  <!--        (user) => {-->
  <!--          onUserSaved(user)-->
  <!--          ok()-->
  <!--        }-->
  <!--      "-->
  <!--    />-->
  <!--  </VaModal>-->
</template>
