<script setup>
import { $api } from '@/utils/api'

const meta = ref({ periods: [], sport_codes: [] })
const periodId = ref(null)
const sportCodeId = ref(null)
const dashboard = ref({ counts: {}, rows: [] })
const confirmOpen = ref(false)
const loading = ref(false)
const results = ref([])

function resultMember(memberId) {
  return dashboard.value.rows.find(row => row.member.id === memberId)?.member
}

async function init() {
  meta.value = await $api('/membership-applications/meta')
  periodId.value = meta.value.periods[0]?.id
  sportCodeId.value = meta.value.sport_codes[0]?.id
}
async function load() {
  if (!periodId.value || !sportCodeId.value) return
  dashboard.value = await $api('/renewals/dashboard', { query: { membership_period_id: periodId.value, sport_code_id: sportCodeId.value } })
}
async function createDrafts() {
  loading.value = true
  try {
    const response = await $api('/renewals/bulk-drafts', { method: 'POST', body: { membership_period_id: periodId.value, sport_code_id: sportCodeId.value } })
    results.value = response.results
    confirmOpen.value = false
    await load()
  } finally { loading.value = false }
}
watch([periodId, sportCodeId], load)
onMounted(init)
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between align-center gap-3 mb-6">
      <div><h3 class="text-h3">Renewal dashboard</h3><p class="text-medium-emphasis">Eligibility is based on the preceding period’s historical membership.</p></div>
      <VBtn :disabled="!dashboard.counts?.not_started" @click="confirmOpen = true">Create eligible drafts</VBtn>
    </div>
    <VRow><VCol cols="12" md="6"><VSelect v-model="periodId" :items="meta.periods" item-title="name" item-value="id" label="Target period" /></VCol><VCol cols="12" md="6"><VSelect v-model="sportCodeId" :items="meta.sport_codes" item-title="name" item-value="id" label="Sport code" /></VCol></VRow>
    <VRow><VCol v-for="(count, key) in dashboard.counts" :key="key" cols="6" md="3"><VCard><VCardText><div class="text-h3">{{ count }}</div><div class="text-capitalize">{{ key.replaceAll('_', ' ') }}</div></VCardText></VCard></VCol></VRow>
    <VCard class="mt-6"><VTable><thead><tr><th>Member</th><th>Previous category</th><th>Status</th></tr></thead><tbody><tr v-for="row in dashboard.rows" :key="row.member.id"><td>{{ row.member.display_name }}</td><td>{{ row.previous_membership.membership_category_id }}</td><td><VChip size="small">{{ row.status.replaceAll('_', ' ') }}</VChip></td></tr></tbody></VTable></VCard>
    <template v-if="results.length">
      <VAlert type="success" variant="tonal" class="mt-4">{{ results.filter(r => r.outcome === 'created').length }} renewal drafts created.</VAlert>
      <VCard class="mt-4">
        <VCardTitle>Bulk renewal outcomes</VCardTitle>
        <VTable>
          <thead><tr><th>Member</th><th>Outcome</th></tr></thead>
          <tbody>
            <tr v-for="result in results" :key="result.member_id">
              <td>{{ resultMember(result.member_id)?.display_name || `Member #${result.member_id}` }}</td>
              <td><VChip size="small">{{ result.outcome.replaceAll('_', ' ') }}</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
    <VDialog v-model="confirmOpen" max-width="520"><VCard><VCardTitle class="pa-6">Create renewal drafts?</VCardTitle><VCardText>This creates draft applications only for {{ dashboard.counts?.not_started || 0 }} eligible members. It will not submit or approve them.</VCardText><VCardActions class="pa-6"><VSpacer /><VBtn variant="tonal" @click="confirmOpen = false">Cancel</VBtn><VBtn :loading="loading" @click="createDrafts">Create drafts</VBtn></VCardActions></VCard></VDialog>
  </div>
</template>
