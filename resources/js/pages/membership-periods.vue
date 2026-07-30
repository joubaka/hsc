<script setup>
import { $api } from '@/utils/api'

const periods = ref([])
const dialog = ref(false)
const saving = ref(false)
const form = reactive({ id: null, name: '', starts_on: '', ends_on: '', applications_open_on: '', applications_close_on: '', renewal_deadline: '', status: 'draft' })
const user = JSON.parse(localStorage.getItem('hsc_admin_user') || '{}')
const canManage = ['hsc_super_admin', 'hsc_admin'].includes(user.role)
const colours = { draft: 'secondary', open: 'success', closed: 'warning', archived: 'dark' }
const load = async () => { periods.value = await $api('/membership-periods') }
function edit(period = null) {
  Object.assign(form, period ? { ...period, starts_on: period.starts_on.slice(0, 10), ends_on: period.ends_on.slice(0, 10), applications_open_on: period.applications_open_on?.slice(0, 10) || '', applications_close_on: period.applications_close_on?.slice(0, 10) || '', renewal_deadline: period.renewal_deadline?.slice(0, 10) || '' } : { id: null, name: '', starts_on: '', ends_on: '', applications_open_on: '', applications_close_on: '', renewal_deadline: '', status: 'draft' })
  dialog.value = true
}
async function save() {
  saving.value = true
  try {
    await $api(form.id ? `/membership-periods/${form.id}` : '/membership-periods', { method: form.id ? 'PUT' : 'POST', body: form })
    dialog.value = false; await load()
  } finally { saving.value = false }
}
async function transition(period, status) {
  await $api(`/membership-periods/${period.id}/transition`, { method: 'POST', body: { status } }); await load()
}
onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-6"><div><h3 class="text-h3">Membership periods</h3><p class="text-medium-emphasis mb-0">Application and renewal years remain available historically.</p></div><VBtn v-if="canManage" prepend-icon="tabler-plus" @click="edit()">Add period</VBtn></div>
    <VCard><VTable><thead><tr><th>Period</th><th>Dates</th><th>Applications</th><th>Renewal deadline</th><th>Status</th><th /></tr></thead><tbody>
      <tr v-for="period in periods" :key="period.id"><td class="font-weight-medium">{{ period.name }}</td><td>{{ period.starts_on.slice(0,10) }} – {{ period.ends_on.slice(0,10) }}</td><td>{{ period.applications_open_on?.slice(0,10) || '—' }} – {{ period.applications_close_on?.slice(0,10) || '—' }}</td><td>{{ period.renewal_deadline?.slice(0,10) || '—' }}</td><td><VChip :color="colours[period.status]" size="small" variant="tonal">{{ period.status }}</VChip></td><td class="text-end"><VBtn v-if="canManage && period.status !== 'archived'" icon="tabler-edit" size="small" variant="text" @click="edit(period)" /><VBtn v-if="canManage && period.status === 'draft'" size="small" variant="tonal" @click="transition(period,'open')">Open</VBtn><VBtn v-if="canManage && period.status === 'open'" size="small" variant="tonal" color="warning" @click="transition(period,'closed')">Close</VBtn><VBtn v-if="canManage && period.status === 'closed'" size="small" variant="text" @click="transition(period,'archived')">Archive</VBtn></td></tr>
    </tbody></VTable></VCard>
    <VDialog v-model="dialog" max-width="720"><VCard><VCardTitle class="pa-6">{{ form.id ? 'Edit period' : 'Add period' }}</VCardTitle><VCardText><VRow><VCol cols="12"><VTextField v-model="form.name" label="Name" /></VCol><VCol cols="6"><VTextField v-model="form.starts_on" type="date" label="Starts" /></VCol><VCol cols="6"><VTextField v-model="form.ends_on" type="date" label="Ends" /></VCol><VCol cols="6"><VTextField v-model="form.applications_open_on" type="date" label="Applications open" /></VCol><VCol cols="6"><VTextField v-model="form.applications_close_on" type="date" label="Applications close" /></VCol><VCol cols="6"><VTextField v-model="form.renewal_deadline" type="date" label="Renewal deadline" /></VCol></VRow></VCardText><VCardActions class="pa-6"><VSpacer /><VBtn variant="tonal" @click="dialog=false">Cancel</VBtn><VBtn :loading="saving" @click="save">Save</VBtn></VCardActions></VCard></VDialog>
  </div>
</template>
