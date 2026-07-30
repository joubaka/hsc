<script setup>
import { $api } from '@/utils/api'

const router = useRouter()
const user = computed(() => {
  try { return JSON.parse(localStorage.getItem('hsc_admin_user')) || {} }
  catch { return {} }
})
const roleLabel = computed(() => (user.value.role || 'administrator').replaceAll('_', ' '))

async function logout() {
  try { await $api('/auth/logout', { method: 'POST' }) }
  finally {
    localStorage.removeItem('hsc_admin_token')
    localStorage.removeItem('hsc_admin_user')
    await router.push('/login')
  }
}
</script>

<template>
  <VAvatar class="cursor-pointer" color="primary" variant="tonal">
    <span class="font-weight-bold">{{ user.name?.charAt(0) || 'H' }}</span>
    <VMenu activator="parent" width="280" location="bottom end" offset="14px">
      <VList>
        <VListItem>
          <VListItemTitle class="font-weight-semibold">{{ user.name || 'HSC Administrator' }}</VListItemTitle>
          <VListItemSubtitle class="text-capitalize">{{ roleLabel }}</VListItemSubtitle>
        </VListItem>
        <template v-if="user.sport_codes?.length">
          <VDivider class="my-2" />
          <VListSubheader>SPORT-CODE SCOPE</VListSubheader>
          <VListItem v-for="code in user.sport_codes" :key="code.id" :title="code.name" prepend-icon="tabler-ball-tennis" />
        </template>
        <VDivider class="my-2" />
        <VListItem title="Sign out" prepend-icon="tabler-logout" @click="logout" />
      </VList>
    </VMenu>
  </VAvatar>
</template>
