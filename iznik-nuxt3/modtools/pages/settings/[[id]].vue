<template>
  <div class="bg-white">
    <div class="p-2 pb-0">
      <ModSettingsSearch @select="goToSetting" />
      <NoticeMessage v-if="needGroup" variant="warning" class="mb-2">
        Pick a community above to see <strong>{{ needGroup }}</strong
        >.
      </NoticeMessage>
    </div>
    <b-tabs v-model="tabIndex" content-class="mt-3" card>
      <b-tab>
        <template #title>
          <h2 class="ms-2 me-2">Personal</h2>
        </template>
        <ModSettingsPersonal />
      </b-tab>
      <b-tab>
        <template #title>
          <h2 class="ms-2 me-2">Community</h2>
        </template>
        <ModSettingsGroup
          ref="groupSettings"
          :initial-group="loadGroup"
          @settings-shown="revealPending"
        />
      </b-tab>
      <b-tab>
        <template #title>
          <h2 class="ms-2 me-2">Standard Messages</h2>
        </template>
        <ModSettingsModConfig />
      </b-tab>
    </b-tabs>
  </div>
</template>

<script setup>
import { nextTick, ref } from 'vue'
import { useRoute } from '#imports'
import { revealSetting } from '~/composables/useSettingsSearch'
import { SETTINGS_TABS } from '~/modtools/utils/settingsIndex'

const tabIndex = ref(0)
const loadGroup = ref(null)
const groupSettings = ref(null)
const needGroup = ref(null)

// A Community setting picked from search before a group was chosen. Held so
// the jump can finish itself once the group's settings appear.
const pendingSetting = ref(null)

const route = useRoute()
loadGroup.value = parseInt(route.params.id) || null

onMounted(() => {
  if (loadGroup.value) {
    // We've been asked to load group setting.
    tabIndex.value = 1
  }
})

/**
 * Jump to a setting picked from search: switch tab, expand its accordion
 * section, then scroll to it and flash it.
 */
async function goToSetting(setting) {
  needGroup.value = null
  pendingSetting.value = null
  tabIndex.value = SETTINGS_TABS[setting.tab]?.index ?? 0

  // Let the tab render before looking for the section or the control.
  await nextTick()

  if (setting.section && groupSettings.value) {
    groupSettings.value.openSection = setting.section

    // And again for the section to expand.
    await nextTick()
  }

  if (!(await revealSetting(setting.id))) {
    // Community settings don't render until a group is chosen. Ask for one,
    // and remember where we were headed so picking a group finishes the job.
    needGroup.value = setting.label
    pendingSetting.value = setting
  }
}

/**
 * Finish a jump that was waiting on a group being chosen.
 */
async function revealPending() {
  const setting = pendingSetting.value

  if (!setting) return

  if (setting.section && groupSettings.value) {
    groupSettings.value.openSection = setting.section
    await nextTick()
  }

  if (await revealSetting(setting.id)) {
    needGroup.value = null
    pendingSetting.value = null
  }
}
</script>
